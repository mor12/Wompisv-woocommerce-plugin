<?php
/* Plugin Class */
class Wompi_Payment_Gateway extends WC_Payment_Gateway {

  function __construct() {
    $this->id = "wompi_payment";

    $this->method_title = __( "WOMPI PAYMENT GATEWAY", 'wompi-payment' );

    $this->method_description = __( "WOMPI Payment Gateway Plug-in for WooCommerce", 'wompi-payment' );

    $this->title = __( "WOMPI Payment Gateway", 'wompi-payment' );

    $this->has_fields = true;

    $this->supports = array( 'default_credit_card_form' );

    $this->init_form_fields();

    $this->init_settings();
    
    foreach ( $this->settings as $setting_key => $value ) {
      $this->$setting_key = $value;
    }
    
    add_action( 'admin_notices', array( $this,  'do_ssl_check' ) );
    
    if ( is_admin() ) {
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }   
  } 


  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'   => __( 'Activar / Desactivar', 'wompi-payment' ),
        'label'   => __( 'Activar este metodo de pago', 'wompi-payment' ),
        'type'    => 'checkbox',
        'default' => 'no',
      ),
      'title' => array(
        'title'   => __( 'Título', 'wompi-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'Título de pago que el cliente verá durante el proceso de pago.', 'wompi-payment' ),
        'default' => __( 'Pago con tarjeta', 'wompi-payment' ),
      ),
      'description' => array(
        'title'   => __( 'Descripción', 'wompi-payment' ),
        'type'    => 'textarea',
        'desc_tip'  => __( 'Descripción de pago que el cliente verá durante el proceso de pago.', 'wompi-payment' ),
        'default' => __( 'Pague con seguridad usando su tarjeta.', 'wompi-payment' ),
        'css'   => 'max-width:350px;'
      ),
      'key_id' => array(
        'title'   => __( 'App id', 'wompi-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de seguridad del panel de control del comerciante.', 'wompi-payment' ),
        'default' => '',
      ),
      'api_key' => array(
        'title'   => __( 'API Secret', 'wompi-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de api del panel de control del comerciante.', 'wompi-payment' ),
        'default' => '',
      ),
    );    
  }

  public function process_payment( $order_id ) {
    global $woocommerce;
    
    $customer_order = new WC_Order( $order_id );

    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://id.wompi.sv/connect/token",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => "grant_type=client_credentials&client_id=".$this->key_id."&client_secret=".$this->api_key."&audience=wompi_api",
      CURLOPT_HTTPHEADER => array(
        "content-type: application/x-www-form-urlencoded"
      ),
    ));


    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
    } else {
      $response = json_decode($response);
      echo $_POST;

      $primer_nombre =  ( isset( $_POST['billing_first_name'] ) ) ? $_POST['billing_first_name'] : '';
      $segundo_nombre =  ( isset( $_POST['billing_last_name'] ) ) ? $_POST['billing_last_name'] : '';
      $fechV = str_replace( array( '/', ' '), '', $_POST['wompi_payment-card-expiry'] );

      $data = array(
        'tarjetaCreditoDebido' => array(
            'numeroTarjeta' =>  $_POST['wompi_payment-card-number'],
            'cvv' => ( isset( $_POST['wompi_payment-card-cvc'] ) ) ? $_POST['wompi_payment-card-cvc'] : '',
            'mesVencimiento' => $fechV[0] . $fechV[1],
            'anioVencimiento' => 20 ."". substr($fechV, -2),
        ),
        'monto' => $customer_order->order_total,
        'emailCliente' =>  ( isset( $_POST['billing_email'] ) ) ? $_POST['billing_email'] : '',
        'nombreCliente' => $primer_nombre . ' '. $segundo_nombre,
        'formaPago' => 'PagoNormal'
    );
    
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, 'https://api.wompi.sv/TransaccionCompra');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $headers = array();
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Authorization: Bearer '.$response->access_token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    
    $result = json_decode($result);

    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);

      if ($result->esAprobada) {
        $customer_order->update_status( 'completed' );

        $customer_order->add_order_note( __( 'Wompi payment completed.' ) );
        $customer_order->add_order_note( __( 'Wompi payment AUTH CODE.', $result->codigoAutorizacion ) );
        $customer_order->add_order_note( __( 'Wompi payment TRANSACTION ID.', $result->idTransaccion ) );

        $order_id = method_exists( $customer_order, 'get_id' ) ? $customer_order->get_id() : $customer_order->ID;

        update_post_meta($order_id , '_wc_order_wompi_authcode', $result->codigoAutorizacion );
        update_post_meta($order_id , '_wc_order_wompi_transactionid', $result->idTransaccion );


        return array(
          'result'   => 'success',
          'redirect' => $this->get_return_url( $customer_order ),
        );
      } else {
        wc_add_notice( $response->mensajes[0], 'error' );
        $customer_order->add_order_note( 'Error: '.$response->mensajes[0] );
      }


    }
 
   

  }

  public function validate_fields() {
    return true;
  }
  
  public function do_ssl_check() {
    if( $this->enabled == "yes" ) {
      if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
        echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";  
      }
    }   
  }

}


add_action( 'woocommerce_admin_order_data_after_billing_address', 'show_wompi_info', 10, 1 );
function show_wompi_info( $order ){
    $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
    echo '<p><strong>'.__('WOMPI Auth Code').':</strong> ' . get_post_meta( $order_id, '_wc_order_wompi_authcode', true ) . '</p>';
    echo '<p><strong>'.__('WOMPI Transaction Id').':</strong> ' . get_post_meta( $order_id, '_wc_order_wompi_transactionid', true ) . '</p>';
}

?>
