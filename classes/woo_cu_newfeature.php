<?php
/*
 *  Channelunity WooCommerce Plugin
 *  Shell for extra features
 */

class woo_cu_feature{

    private $feature='name_from_class_without_woo_cu';
    private $menuTitle="Appear in menu and title";
    private $woofeature;
    
    public function __construct(){
        $this->woofeature=="woo_cu_".$this->feature;
        $this->add_actions();
    }

    //Actions and filters
    public function add_actions(){
        //Add tool to CU menu
        add_action('admin_menu', array($this,'add_to_menu'),90);

        //Queue scripts
        add_action( 'admin_enqueue_scripts', array($this,'register_scripts') );

        //Ajax actions
        add_action('wp_ajax_channelunity_something', array($this,'do_function'));
    }

    //Register javascript/css
    public function register_scripts(){
        //JS
        wp_register_script($this->woofeature, plugins_url('../js/'.$this->woofeature.'.js', __FILE__));
        wp_enqueue_script($this->woofeature);

        $php_import=array(
            
        );

        //send php variables to javascript
        wp_localize_script($this->woofeature,'php_import',$php_import);
    }

    //Add feature to WooCommerce Products menu
    public function add_to_menu(){
        add_submenu_page( 'channelunity-menu', $this->menuTitle, $this->menuTitle,
            'manage_options', $this->feature.'-menu', array($this,'render') );
    }

    //Draw the feature
    public function render(){
        echo woo_cu_helpers::get_html_block($this->feature);
    }

 

 
}