<?php

namespace Drupal\commerce_interswitch_webpay\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * PaymentOffsiteForm.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  // Define URLs and API endpoints.
  const COMMERCE_INTERSWITCH_LIVE_PAY = 'https://webpay.interswitchng.com/paydirect/pay';
  const COMMERCE_INTERSWITCH_TEST_PAY = 'https://stageserv.interswitchng.com/test_paydirect/pay';
  const COMMERCE_INTERSWITCH_LIVE_LOOKUP = 'https://webpay.interswitchng.com/paydirect/api/v1/gettransaction.json';
  const COMMERCE_INTERSWITCH_TEST_LOOKUP = 'https://sandbox.interswitchng.com/webpay/api/v1/gettransaction.json';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $redirect_method = 'get';
    $order = $payment->getOrder();
    $amount = $payment->getAmount()->getNumber() * 100;
    $timestamp = \Drupal::time()->getCurrentTime();
    $transaction_id = $order->id() . 'X' . $timestamp;
    $userCurrent = \Drupal::currentUser();
    $user = User::load($userCurrent->id());
    $name = $user->getUsername();
    // $siteName = \Drupal::config('system.site')->get('name');
    $host = 'www.' . \Drupal::request()->getHost();
    $hash = hash(
      'sha512',
      $transaction_id
      . $payment_gateway_plugin->getConfiguration()['product_id']
      . $payment_gateway_plugin->getConfiguration()['pay_item_id']
      . $amount
      . $form['#return_url']
      . $payment_gateway_plugin->getConfiguration()['mac_key']
    );

    $data = [
      // Interswitch parameters.
      'product_id' => $payment_gateway_plugin->getConfiguration()['product_id'],
      'site_redirect_url' => $form['#return_url'],
      'txn_ref' => $transaction_id,
      'hash' => $hash,
      'amount' => $amount,
      'order_id' => $order->id(),
      'currency' => $payment_gateway_plugin->getConfiguration()['currency_code'],
      'site_name' => $host,
      'cust_name' => $name,
      'cust_name_desc' => 'Customer username',
      'cust_id' => $order->getEmail(),
      'cust_id_desc' => 'Customer email address',
      'pay_item_id' => $payment_gateway_plugin->getConfiguration()['pay_item_id'],
      'email' => $order->getEmail(),
      // Feedback URLs.
      'ACCEPTURL' => $form['#return_url'],
      'DECLINEURL' => $form['#return_url'],
      'EXCEPTIONURL' => $form['#return_url'],
      'CANCELURL' => $form['#cancel_url'],
    ];

    $mode = $payment_gateway_plugin->getConfiguration()['mode'];
    if ($mode == 'live') {
      $redirect_url = self::COMMERCE_INTERSWITCH_LIVE_PAY;
    }
    else {
      $redirect_url = self::COMMERCE_INTERSWITCH_TEST_PAY;
    }

    $form = $this->buildRedirectForm($form, $form_state, $redirect_url, $data, $redirect_method);

    return $form;
  }

}
