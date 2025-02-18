<?php 
namespace Drupal\qad_erp\Plugin\WebformHandler; 
use Drupal\Component\Utility\Xss; 
use Drupal\Core\Form\FormStateInterface; 
use Drupal\Core\Render\Markup; 
use Drupal\webform\Plugin\WebformHandlerBase; 
use Drupal\webform\WebformInterface; 
use Drupal\webform\WebformSubmissionInterface; 
use Symfony\Component\DependencyInjection\ContainerInterface; 
use GuzzleHttp\Client;
use Drupal\webform\Entity\Webform;
use Drupal\file\Entity\File;




/** 
 * ERP Custom Webform Handler. 
 * 
 * @WebformHandler( 
 *   id = "erp", 
 *   label = @Translation("ERP"), 
 *   category = @Translation("ERP Webform Handler"), 
 *   description = @Translation("ERP custom webform submission handler."), 
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE, 
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED, 
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED, 
 * ) 
 */ 
class ErpWebformHandler extends WebformHandlerBase { 
  /** 
   * The token manager. 
   * 
   * @var \Drupal\webform\WebformTokenManagerInterface 
   */ 
  protected $tokenManager; 

  /** 
   * {@inheritdoc} 
   */ 
  public static function create(
    ContainerInterface $container, 
    array $configuration, 
    $plugin_id, 
    $plugin_definition) { 
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition); 
    $instance->tokenManager = $container->get('webform.token_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'auth_url' => '',
      'tenant_id' => '',
      'client_id' => '',
      'client_secret' => '',
      'grant_type' => '',
      'resource' => '',
      'post_url' =>'',
      'field_mappings' => [],
      'message' => 'Message',
       
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $webform_id = $this->getWebform()->id();
    $webform = Webform::load($webform_id);
    $elements = $webform->getElementsDecoded();

    // Initialize field mappings
    $form['field_mappings'] = [
        '#type' => 'details',
        '#title' => $this->t('Field Mappings'),
        '#open' => TRUE,
        '#description' => $this->t('Map API fields to webform fields.'),
    ];

    $this->extractFields($elements, $form, $this->configuration);

    // API Authentication Section
    $form['api_auth'] = [
        '#type' => 'details',
        '#title' => $this->t('API Authentication'),
        '#open' => FALSE,
    ];
    $form['api_auth']['auth_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Authentication URL'),
        '#default_value' => $this->configuration['auth_url'],
        '#required' => TRUE,
        '#description' => $this->t('Please enter Authentication URL.'),
    ];
    $form['api_auth']['tenant_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Tenant ID'),
        '#default_value' => $this->configuration['tenant_id'],
        '#required' => TRUE,      
        '#description' => $this->t('Please enter Tenant ID.'),
    ];
    $form['api_auth']['client_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $this->configuration['client_id'],
        '#required' => TRUE,      
        '#description' => $this->t('Please enter Client ID.'),
    ];
    $form['api_auth']['client_secret'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Client Secret Key'),
        '#default_value' => $this->configuration['client_secret'],
        '#required' => TRUE,      
        '#description' => $this->t('Please enter Client Secret Key.'),
    ];
    $form['api_auth']['grant_type'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Grant Type'),
        '#default_value' => $this->configuration['grant_type'],
        '#required' => TRUE,      
        '#description' => $this->t('Please enter Grant type.'),
    ];
    $form['api_auth']['resource'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Resource'),
        '#default_value' => $this->configuration['resource'],
        '#required' => TRUE,      
        '#description' => $this->t('Please enter Resource.'),
    ];

    // POST API Section
    $form['post_api'] = [
        '#type' => 'details',
        '#title' => $this->t('Post API'),
        '#open' => FALSE,
    ];
    $form['post_api']['post_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('POST URL'),
        '#default_value' => $this->configuration['post_url'],
        '#required' => TRUE,
        '#description' => $this->t('Please enter POST URL.'),
    ];

    return $this->setSettingsParents($form);
  }

  private function extractFields(array $elements, &$form, $configuration, $parent_key = '') {
    foreach ($elements as $key => $element) {
      $full_key = $parent_key ? $parent_key . '_' . $key : $key;


      if (is_array($element) && (!isset($element['#type']) || in_array($element['#type'], ['container', 'webform_wizard_page', 'webform_actions','webform_flexbox']))) {
          $this->extractFields($element, $form, $configuration, $full_key);
          continue;
      }

      // Skip elements without a defined #type
      if (!isset($element['#type']) || empty($element['#type'])) {
          continue;
      }

      // Special handling for file fields
      if ($element['#type'] == 'managed_file') {
        $form['field_mappings'][$full_key . '_base64'] = [
            '#type' => 'textfield',
            '#title' => $this->t($element['#title'] ?? $key) . ' Base64 String',
            '#default_value' => $configuration['field_mappings'][$full_key . '_base64'] ?? '',
            '#description' => $this->t('Base64 encoded value of the file.'),
        ];

        $form['field_mappings'][$full_key . '_file_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t($element['#title'] ?? $key) . ' File Name',
            '#default_value' => $configuration['field_mappings'][$full_key . '_file_name'] ?? '',
            '#description' => $this->t('Name of the file uploaded.'),
        ];
    } else {
        // Generic field mapping
        $form['field_mappings'][$full_key] = [
            '#type' => 'textfield',
            '#title' => $this->t($element['#title'] ?? $key),
            '#default_value' => $configuration['field_mappings'][$full_key] ?? '',
            '#description' => $this->t('Enter the mapping value for this field.'),
        ];
      }
    }
  }



  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function alterElements(array &$elements, WebformInterface $webform) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function overrideSettings(array &$settings, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
    if ($value = $form_state->getValue('element')) {
      $form_state->setErrorByName('element', $this->t('The element must be empty. You entered %value.', ['%value' => $value]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    parent::submitForm($form, $form_state, $webform_submission);
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $message = $this->configuration['message'];
    $message = $this->replaceTokens($message, $this->getWebformSubmission());
    $token = $this->getToken();
    $mapped_data = $this->processSubmission($webform_submission);
    $this->findOrCreateApplicantExternal($token, $mapped_data);
    $this->messenger()->addStatus(Markup::create(Xss::filter($message)), FALSE);
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preCreate(array &$values) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postCreate(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postLoad(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preDelete(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  // public function post $this->configuration['debug'] = (bool) $form_state->getValue('debug');Delete(WebformSubmissionInterface $webform_submission) {
  //   $this->debug(__FUNCTION__);
  // }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $this->debug(__FUNCTION__, $update ? 'update' : 'insert');
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessConfirmation(array &$variables) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function createHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function updateHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function createElement($key, array $element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function updateElement($key, array $element, array $original_element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteElement($key, array $element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * Display the invoked plugin method to end user.
   *
   * @param string $method_name
   *   The invoked method name.
   * @param string $context1
   *   Additional parameter passed to the invoked method name.
   */
  protected function debug($method_name, $context1 = NULL) {
    if (!empty($this->configuration['debug'])) {
      $t_args = [
        '@id' => $this->getHandlerId(),
        '@class_name' => get_class($this),
        '@method_name' => $method_name,
        '@context1' => $context1,
      ];
      $this->messenger()->addWarning($this->t('Invoked @id: @class_name:@method_name @context1', $t_args), TRUE);
    }
  }

 /**
   * Get Token of AzureAD
   */
  public function getToken(){
    $token = \Drupal::state()->get('auth_token');
    $tokenTimestamp = \Drupal::state()->get('auth_token_timestamp');

    // Refresh if token is missing or expired (valid for 30 min)
    if (!$token || (time() - $tokenTimestamp) > 1800) {
        $client = new Client();
        try {
            $response = $client->post($this->configuration['auth_url'] . '/' . $this->configuration['tenant_id'] . "/oauth2/token", [
                'form_params' => [
                    'client_id' => $this->configuration['client_id'],
                    'client_secret' => $this->configuration['client_secret'],
                    'grant_type' => $this->configuration['grant_type'],
                    'resource' => $this->configuration['resource'],
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            $accessToken = $tokenData['access_token'] ?? null;

            if ($accessToken) {
                \Drupal::state()->set('auth_token', $accessToken);
                \Drupal::state()->set('auth_token_timestamp', time());
            } else {
                \Drupal::logger('qad_erp')->error('Access token missing in response');
            }

            return $accessToken;
        } catch (\Exception $e) {
            \Drupal::logger('qad_erp')->error('HTTP Client error on token generate: ' . $e->getMessage());
        }
    }

    return $token;
  }
  public function processSubmission(WebformSubmissionInterface $submission) {
    $data = $submission->getData();
    $mapped_data = [];
    $webform = $submission->getWebform();
    $elements = $webform->getElementsDecoded();

    // Helper function to process each element and its children
    $this->processElements($elements, $data, $mapped_data);

    return $mapped_data;
}

private function processElements(array $elements, array $data, array &$mapped_data, $parent_key = '') {
    foreach ($elements as $key => $element) {
        $full_key = $parent_key ? $parent_key . '_' . $key : $key;

        if (is_array($element) && (!isset($element['#type']) || in_array($element['#type'], ['container', 'webform_wizard_page', 'webform_actions', 'webform_flexbox']))) {
            $this->processElements($element, $data, $mapped_data, $full_key);
            continue;
        }

        if (!isset($element['#type']) || empty($element['#type'])) {
            continue;
        }

        if ($element['#type'] == 'managed_file') {
            if (!empty($data[$key])) {
                $file = \Drupal::entityTypeManager()->getStorage('file')->load($data[$key]);
                if ($file) {
                    $file_uri = $file->getFileUri();
                    $file_contents = file_get_contents(\Drupal::service('file_system')->realpath($file_uri));

                    // Encode the file contents in base64
                    $base64_data = base64_encode($file_contents);

                    // Get the file's name
                    $file_name = $file->getFilename();

                    $mapped_data[$full_key . '_base64'] = $base64_data;
                    $mapped_data[$full_key . '_file_name'] = $file_name;
                }
            }
        } else {
            // Generic field mapping for regular fields
            $mapped_data[$full_key] = $data[$key] ?? '';
        }
    }
}


  public function findOrCreateApplicantExternal($token, $mapped_data) {
    $client = new Client();
    $request_data = [];
    $mapping_fields = $this->configuration['field_mappings'];

    foreach ($mapped_data as $field_name => $field_value) {
        if (is_array($field_value) && isset($field_value[$field_name . '_base64']) && isset($field_value[$field_name . '_file_name'])) {

            $base64_field = $this->configuration['field_mappings'][$field_name . '_base64'];
            $filename_field = $this->configuration['field_mappings'][$field_name . '_file_name'];

            $request_data[$base64_field] = $field_value[$field_name . '_base64'];
            $request_data[$filename_field] = $field_value[$field_name . '_file_name'];
            break;
        }
    }

    foreach ($mapping_fields as $field => $mapped_field) {
        if (isset($mapped_data[$field])) {
            $request_data[$mapped_field] = $mapped_data[$field] ?? null;
        }
    }
dd($request_data, $mapping_fields,$mapped_data);
    try {
      $response = $client->post($this->configuration['post_url'], [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
        ],
        'json' => $request_data,
      ]);
      $data = json_decode($response->getBody()->getContents(), true);
      //dd($data);
      return $data;
        } 
    catch (\GuzzleHttp\Exception\ClientException $e) {
      \Drupal::logger('qad_erp')->error('Error ' . $e->getMessage());
        }

  }


  

}