<?php
/*
Plugin Name: Integracion con WOMPIsv
Plugin URI: https://github.com/mor12/Wompisv-woocommerce-plugin
Description:  gateway de pago WOMPI.
Version: 1.0.0
Author: Jose Escobar
*/

 
  add_action( 'plugins_loaded', 'wompi_payment_init', 0 );
  function wompi_payment_init() {
 
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
    
    include_once( 'wc-wompi-payment.php' );


    add_filter( 'woocommerce_payment_gateways', 'add_wompi_payment_gateway' );
    function add_wompi_payment_gateway( $methods ) {
      $methods[] = 'Wompi_Payment_Gateway';
      return $methods;
    }
  }


  add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wompi_payment_action_links' );
  function wompi_payment_action_links( $links ) {
    $plugin_links = array(
      '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'wompi-payment' ) . '</a>',
    );

    return array_merge( $plugin_links, $links );
  }