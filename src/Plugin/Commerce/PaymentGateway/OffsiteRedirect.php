<?php

namespace Drupal\commerce_interswitch_webpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_interswitch_webpay_offsite_redirect",
 *   label = "Commerce Interswitch WebPay Redirect",
 *   display_label = "Commerce Interswitch WebPay",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_interswitch_webpay\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "verve", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

  // Define URLs and API endpoints.
  const COMMERCE_INTERSWITCH_LIVE_PAY = 'https://webpay.interswitchng.com/paydirect/pay';
  const COMMERCE_INTERSWITCH_TEST_PAY = 'https://sandbox.interswitchng.com/webpay/pay';
  const COMMERCE_INTERSWITCH_LIVE_LOOKUP = 'https://webpay.interswitchng.com/paydirect/api/v1/gettransaction.json';
  const COMMERCE_INTERSWITCH_TEST_LOOKUP = 'https://sandbox.interswitchng.com/webpay/api/v1/gettransaction.json';

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new OffsiteRedirect object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, Client $http_client, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $http_client;
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->messenger = $messenger;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mac_key' => '',
      'product_id' => '',
      'pay_item_id' => '',
      'currency_code' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['mac_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('MAC Key'),
      '#description' => $this->t('Your Interswitch MAC Key'),
      '#maxlength' => 128,
      '#size' => 64,
      '#default_value' => $this->configuration['mac_key'],
      '#required' => TRUE,
    ];
    $form['product_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product ID'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $this->configuration['product_id'],
      '#required' => TRUE,
    ];
    $form['pay_item_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pay Item ID'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $this->configuration['pay_item_id'],
      '#required' => TRUE,
    ];
    $form['currency_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#options' => ['NGN' => 'Naira - NGN'],
      '#default_value' => 'NGN',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['mac_key'] = $values['mac_key'];
      $this->configuration['product_id'] = $values['product_id'];
      $this->configuration['pay_item_id'] = $values['pay_item_id'];
      $this->configuration['currency_code'] = $values['currency_code'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $transactionId = $request->query->get('txn_ref');
    if (empty($transactionId)) {
      throw new PaymentGatewayException('No valid transaction id found on return.');
    }
    $transaction = $this->lookupTransaction($transactionId, $order);

    switch ($transaction['status']) {
      case 'success':
        $payment = $this->paymentStorage->create([
          'state' => 'authorization',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->parentEntity->id(),
          'order_id' => $order->id(),
          'test' => $this->getMode() == 'test',
          'txn_ref' => $transactionId,
          'remote_id' => $transaction['PaymentReference'],
          'remote_state' => $transaction['ResponseCode'],
          'remote_message' => $transaction['ResponseDescription'],
          'authorized' => $this->time->getCurrentTime(),
        ]);
        $payment->save();

        $this->messenger->addStatus($this->t('<h6>Your transaction was successful.</h6> Transaction Id : @txn_ref<br/> Payment Reference : @payreference<br/> An email with the transaction details has been sent to your email address : @email',
          [
            '@txn_ref' => $transactionId,
            '@payreference' => $transaction['PaymentReference'],
            '@email' => $order->mail,
          ]
        ));
        break;

      case 'pending':
        $this->messenger->addStatus($this->t('<h6>Your transaction is pending.</h6> Transaction Id : @txn_ref<br/> Payment Reference : @payreference<br/> An email with the transaction details has been sent to your email address : @email',
          [
            '@txn_ref' => $transactionId,
            '@payreference' => $transaction['PaymentReference'],
            '@email' => $order->mail,
          ]
        ));
        break;

      case 'failure':
        $this->messenger->addError($this->t('Your transaction was not successful.'));
        break;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $this->messenger->addStatus($this->t('Your transaction was cancelled.'));
  }

  /**
   * {@inheritdoc}
   */
  private function lookupTransaction($transactionId, $order) {
    $url = $this->getApiUrl();
    $amount = $this->getAmount($order);
    $hash = $this->getHash($transactionId);
    $options['headers'] = ['Hash' => $hash];
    $url .= "?productid={$this->configuration['product_id']}&
      transactionreference=$transactionId&
      amount=$amount";
    $response = $this->httpClient->get($url, [RequestOptions::QUERY => $options]);
    $json_response = json_decode($response->getBody(), TRUE);

    if (empty($json_response['txn_ref'])) {
      throw new InvalidResponseException($this->t('Unable to identify payment transaction'));
    }

    switch ($json_response['ResponseCode']) {
      case '00':
      case '11':
        $json_response['status'] = 'success';
        break;

      case '09':
      case '10':
        $json_response['status'] = 'pending';
        break;

      default:
        $json_response['status'] = 'failure';
    }

    return $json_response;

  }

  /**
   * {@inheritdoc}
   */
  private function getApiUrl() {
    if ($this->getMode() == 'test') {
      return self::COMMERCE_INTERSWITCH_TEST_PAY;
    }
    else {
      return self::COMMERCE_INTERSWITCH_LIVE_PAY;
    }
  }

  /**
   * {@inheritdoc}
   */
  private function getAmount(OrderInterface $order) {
    return $order->getTotalPrice()->getNumber() * 100;
  }

  /**
   * {@inheritdoc}
   */
  private function getHash($transactionId) {
    return hash(
      'sha512',
      $this->configuration['product_id'] .
        $transactionId .
        $this->configuration['mac_key']
    );
  }

}
