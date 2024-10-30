<?php
/*
 *  Channelunity WooCommerce Plugin
 *  Import images from URLS
 * 
 * The import runs as a background process by making an ajax call into itself
 */

class woo_cu_imageimport{

    private $feature='imageimport';
    private $menuTitle="Import images from URLS";
    private $woofeature;
    private $running;
    private $fields;
    private $fieldSql;
    private $fieldQuery;
    private $debug;
    private $failedImages;
    
    public function __construct(){
        $this->woofeature="woo_cu_".$this->feature;
        $this->running=get_option('channelunity_imageimport_running'); //Check if the process is already running
        $this->add_actions();
    }

    //Actions and filters
    public function add_actions(){
        //Add tool to CU menu
        add_action('admin_menu', array($this,'add_to_menu'),90);

        //Queue scripts
        add_action( 'admin_enqueue_scripts', array($this,'register_scripts') );

        //Ajax actions
        add_action('wp_ajax_channelunity_imageimport_count', array($this,'count'));
        add_action('wp_ajax_channelunity_imageimport_start_process', array($this,'process'));
        add_action('wp_ajax_channelunity_imageimport_check_process', array($this,'check_process'));
        add_action('wp_ajax_channelunity_imageimport_reset', array($this,'reset'));
        add_action('wp_ajax_channelunity_imageimport_failed', array($this,'check_failed'));
        
        //Ajax actions for background process
        add_action('wp_ajax_channelunity_imageimport_do_process', array($this,'start_process'));
        add_action('wp_ajax_nopriv_channelunity_imageimport_do_process', array($this,'start_process'));
    }

    //Register javascript/css
    public function register_scripts(){
        //JS
        wp_register_script($this->woofeature, plugins_url('../js/'.$this->woofeature.'.js', __FILE__));
        wp_enqueue_script($this->woofeature);

        $php_import=array(
            'running'=>$this->running
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

    //Ajax call to return product count
    public function count(){
        echo count($this->getProductList());
        wp_die();
    }
    
    //Ajax call to begin the process
    public function process(){
        update_option('channelunity_imageimport_progress','0');
        $output=$this->call_background_process();
        echo "Starting...";
        wp_die();
    }

    public function reset(){
        global $wpdb;
        $result=$wpdb->get_col("select distinct post_id from {$wpdb->prefix}postmeta where meta_key like '\_cuimageimport\_%'",0);
        $count=count($result);
        $wpdb->query("update {$wpdb->prefix}postmeta set meta_key=replace(meta_key,'_cuimageimport_','') where meta_key like '\_cuimageimport\_%'");
        echo $count;
        wp_die();
    }
    
    // Dispatch the async request
    private function call_background_process() {
        $this->setFields();
            $url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
            $args = $this->get_post_args();
            return wp_remote_post(esc_url_raw($url),$args);
    }
    
    //Ajax endpoint to begin the background process
    public function start_process() { 
        session_write_close();
        $this->do_process();
        echo "Started";
        wp_die();
    }
    
    //Get the percentage complete
    public function check_process(){
        echo get_option('channelunity_imageimport_progress');
        wp_die();
    }
    
    public function check_failed(){
        $failed=get_option('channelunity_imageimport_failed');
        if(count($failed)){
            foreach($failed as $fail){
                echo $fail;
            }
        } else {
            echo "none";
        }
        wp_die();
    }

    //The actual process
    private function do_process() {
        global $wpdb;
        $post_ids=$this->getProductList();
        if($post_ids=='csv'){$this->csv_import();exit;}
        update_option('channelunity_imageimport_running','true');
        $count=count($post_ids);
        if($count){
            update_option('channelunity_imageimport_running','true');
            $current=0;
            $this->failedImages=array();
            foreach($post_ids as $id){
                $query="select meta_value from {$wpdb->prefix}postmeta where post_id=$id and ($this->fieldSql)";
                $urls=$wpdb->get_col($query,0); 
                if(count($urls)){
                    if(count($urls)==1){
                        $this->add_product_image($urls[0],$id);
                    } else {
                        $this->add_product_gallery($urls,$id);  
                    }
                }
                $current++;
                $progress="$current of $count";
                update_option('channelunity_imageimport_progress',$progress);
            }
            $this->halt();
        } else {
            $this->halt('notfound');          
        }
    }   

    private function csv_import(){
        update_option('channelunity_imageimport_running','true');
        update_option('channelunity_imageimport_progress','Starting CSV Import');
        try{
            $dir=WP_PLUGIN_DIR . '/' . trim(dirname(plugin_basename(__FILE__)), '/');
            $file="$dir/wizardimages.csv";
            $this->debug("CSV Filename $file");
            $csv=explode("\n",file_get_contents($file));
            $count=count($csv);
            if($count==1){
                $this->debug($csv);
                $this->halt();
            }
            $current=0;
            $this->debug("CSV Line count : $count");
            foreach($csv as $line){
                $progress="$current of $count";
                update_option('channelunity_imageimport_progress',$progress);            
                $data=explode('|',$line);
                $sku=trim($data[0]);
                $item_id=trim($data[1]);
                $post_id=$this->find_product_id($sku,$item_id);
                if($post_id){
                    $this->debug("Looking for URLs");
                    for($x=1;$x<4;$x++){
                        $col=$x+1;
                        $url=trim($data[$col]);
                        $this->debug("Column $col is $url");
                        if($url){
                            $this->debug("Adding postmeta $post_id - cu_image{$x} - url $url");
                            update_post_meta($post_id,"cu_image{$x}",$url);
                        }
                    }
                }
                $current++;
            }
        } catch (Exception $e){
            $err=implode('|',$e);
            $this->failedImages[]="<tr><td>ERROR</td><td>$err</td></tr>";
            $this->halt();             
        }
        $this->halt();      
    }
    
    private function find_product_id($sku,$item_id){
        global $wpdb;
        try{
            $select="select post_id from {$wpdb->postmeta} inner join {$wpdb->posts} 
                                    on post_id=id where (post_type='product' or post_type='product_variation') 
                                    and meta_key='_sku' and meta_value='$sku'";
            $this->debug($select);
            $result=$wpdb->get_col($select,0);
            $this->debug("Sku result ".print_r($result,true));
            if(count($result)==0){
                $select="select post_id from {$wpdb->postmeta} inner join {$wpdb->posts} 
                                        on post_id=id where (post_type='product' or post_type='product_variation') 
                                        and meta_key='_sku' and meta_value='$item_id'";
                $this->debug($select);
                $result=$wpdb->get_col($select,0);            
                $this->debug("Item ID result".print_r($result,true));
            }
            
            if(count($result)==0){
                $this->debug("Failed ".print_r($result,true));
                $this->failedImages[]="<tr><td>No product id for</td><td>$sku / $item_id</td></tr>"; 
                return false;            
            } else {
                $this->debug("Post id found: ".print_r($result[0],true));
                return $result[0];
            }
        } catch (Exception $e){
            $err=implode('|',$e);
            $this->failedImages[]="<tr><td>ERROR</td><td>$err</td></tr>";
            $this->halt();
        }
    }
    
    private function setFields(){
        $this->fields=preg_replace('/[^\w-,]/', '',$_REQUEST['fields']);                      
    }
    
    private function getProductList(){
        $this->setFields();
        if($this->fields=='csv'){
            //$this->debug="Debug on\n";
            $this->debug("Debug csv ");
            return 'csv';
        }
        global $wpdb;
        $fieldarray=explode(',',$this->fields);
        if(@$fieldarray[0]=='debug'){
            unset($fieldarray[0]);
            $this->debug="Debug on\n";
        }
        array_walk($fieldarray,function(&$field){$field="meta_key='$field' and meta_value!=''";});
        $this->fieldSql=implode(' or ',$fieldarray);
        $query="select distinct post_id from {$wpdb->prefix}postmeta where {$this->fieldSql}";
        $this->fieldQuery=$query;
        $results=$wpdb->get_results($query,ARRAY_A);
        $ids=array();
        foreach($results as $r){
            $ids[]=$r['post_id'];
        }
        return $ids;
    }
    
    //Add image to product
    private function add_product_image($url,$product_id){
        if(strlen(trim($url))>0){
            $name=basename($url);
            $attachment_id=media_sideload_image($url,$product_id,$name,'id');
            if(is_numeric($attachment_id)){
                update_post_meta($product_id, '_thumbnail_id', $attachment_id);
                $this->clear_postmeta($product_id,$url);
            } else {
                $this->add_failed_image($product_id,$url);
            }
        }
    }
    
    //Add gallery to product
    private function add_product_gallery($urls,$product_id){
        //Make the first image the normal product image, use the rest for the gallery
        $this->add_product_image(array_shift($urls),$product_id);
        $ids=array();
        foreach($urls as $url){
            if(strlen(trim($url))>0){
                $name=basename($url);
                $attachment_id=media_sideload_image($url,$product_id,$name,'id');
                if(is_numeric($attachment_id)){
                    $ids[]=$attachment_id;
                    $this->clear_postmeta($product_id,$url);
                } else {
                    $this->add_failed_image($product_id,$url);
                }
            }
        }
        if(count($ids)){
            update_post_meta($product_id, '_product_image_gallery', implode(',', $ids));  
        }
    }
    
    private function add_failed_image($product_id,$url){
        $this->failedImages[]="<tr><td class='column-columnname'>$product_id</td>
            <td class='column-columnname'>$url</td></tr>";
    }
    
    //Empty postmeta after downloading
    private function clear_postmeta($post_id,$url=false){
        global $wpdb;
        if($url){
            $url="and meta_value='$url'";
        }
        $fields=explode(',',$this->fields);
        foreach($fields as $field){
            $wpdb->query("update {$wpdb->prefix}postmeta set meta_key=concat('_cuimageimport_',meta_key) 
                where meta_key='$field' and post_id=$post_id $url");
        }
    }
    
    private function get_query_args() {
        if ( property_exists( $this, 'query_args' ) ) {
                return $this->query_args;
        }
        return array('action' =>'channelunity_imageimport_do_process');
    }

    private function get_query_url() {
        if ( property_exists( $this, 'query_url' ) ) {
                return $this->query_url;
        }
        return admin_url('admin-ajax.php');
    }

    private function get_post_args() {
        if ( property_exists( $this, 'post_args' ) ) {
                return $this->post_args;
        }

        return array(
            'timeout'   => 2,
            'blocking'  => false,
            'body'      => array('fields'=>$this->fields),
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        );
    }
    
    private function halt($status='complete'){
        update_option('channelunity_imageimport_failed',$this->failedImages);
        update_option('channelunity_imageimport_progress',$status);
        update_option('channelunity_imageimport_running','');         
        die();
    }
    
    private function debug($msg){
        if($this->debug){
            $this->debug.=print_r($msg,true)."\n";
            update_option('channelunity_imageimport_debug',$this->debug);            
        }
    }
}