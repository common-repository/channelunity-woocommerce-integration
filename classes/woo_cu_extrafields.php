<?php
/*
 *  Channelunity WooCommerce Plugin
 *  Custom product and variation fields
 */

class woo_cu_extrafields{

    private $fields;

    public function __construct() {
        $this->channelunity_add_actions();
    }

    //Add wordpress actions
    private function channelunity_add_actions(){
        //Add configuration page to CU menu
        add_action('admin_menu',array($this,'channelunity_register_extrafields'),90);

        //Queue scripts
        add_action( 'admin_enqueue_scripts', array($this,'channelunity_register_scripts') );

        // Add Variation fields
        add_action( 'woocommerce_product_after_variable_attributes', array($this,'channelunity_variation_fields'),10,3);

        // Save Variation fields
        add_action( 'woocommerce_save_product_variation', array($this,'channelunity_save_variation_fields'),10,2);

        // Add main product fields
        add_action( 'woocommerce_product_options_general_product_data', array($this,'channelunity_general_fields'));

        // Save main product fields
        add_action( 'woocommerce_process_product_meta', array($this,'channelunity_save_general_fields'));

        //Add extra fields to api response
        add_action( 'woocommerce_api_product_response' , array($this,'channelunity_add_extra_data'));

        //Ajax actions
        add_action('wp_ajax_channelunity_update_extrafields', array($this,'channelunity_update_extrafields'));
        add_action('wp_ajax_channelunity_delete_extrafields', array($this,'channelunity_delete_extrafields'));
        add_action('wp_ajax_channelunity_redraw_extrafields', array($this,'channelunity_redraw_extrafields'));

        //add_filter('woocommerce_product_data_tabs',array($this,'channelunity_add_variation_custom'));
        //add_action( 'woocommerce_product_data_panels', array($this,'channelunity_custom_fields'));
    }

    //Add 'custom' tab to variation products. Removed from 2.5 onwards
    public function channelunity_add_variation_custom($product_data_tabs){
        $product_data_tabs['custom']=array(
            'label'  => __( 'Custom', 'woocommerce' ),
            'target' => 'custom_product_data',
            'class'  => array('show_if_variable'),
        );
        return $product_data_tabs;
    }

    //Add fields to 'custom' tab. Removed from 2.5 onwards
    public function channelunity_custom_fields(){
        $pid=get_the_ID();
        $pd=wc_get_product($pid);
        if($pd->is_type('variable')) {
            echo '<div id="custom_product_data" class="panel woocommerce_options_panel show_if_variable">';
            $this->channelunity_general_fields(true);
            echo '</div>';
        }
    }

    //Register javascript/css
    public function channelunity_register_scripts(){
        //JS
        wp_register_script('woo_cu_extrafields', plugins_url('../js/woo_cu_extrafields.js', __FILE__));
        wp_enqueue_script('woo_cu_extrafields');

        $php_import=array(
            'ajaxurl'       => admin_url( 'admin-ajax.php' ),
        );

        //send php variables to javascript
        wp_localize_script('woo_cu_extrafields','php_import',$php_import);
    }
    
    //Add tool to WooCommerce Products menu
    public function channelunity_register_extrafields(){
        add_submenu_page('channelunity-menu', 'Configure custom fields', 'Custom fields',
            'manage_options', 'extrafields-menu', array($this,'channelunity_render_extrafields'));
    }

    //Draw the tool
    public function channelunity_render_extrafields(){
        echo woo_cu_helpers::get_html_block('extrafields',array('fieldhtml'=>$this->channelunity_get_extrafields()));
    }

    //Get html for each field
    public function channelunity_get_extrafields(){
        //Add new field
        $html=$this->get_extrafield_html().'<br><h4>Current fields:</h4><hr>';
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        //Existing fields
        if(is_array($fields)){
            foreach($fields as $field=>$data){
                $html.=$this->get_extrafield_html($field,$data);
            }
        }
        return $html;
    }

    //Get html for one field
    private function get_extrafield_html($field=false,$data=false){
        if(!$field) {
            $border="style='border-color:#55AA55;'";
            $submit='Add';
            $disabled='';
            $data=array('display'=>'','position'=>'');
        } else {
            $border='';
            $submit='Update';
            $disabled='disabled';
        }
        $position=$data['position'];
        $display=$data['display'];

        $html=  "<div class='cu_generic_container' $border>";
        if($border) {
            $html.="<h2>New field</h2><hr>";
        }

        $html.= "<div style='float:left; display:inline-block;'>" .
                    "<label class='cu_label'>Display name</label>" .
                    "<input type='text' id='cu_extrafield_$field' value='$display'><br><br>" .
                    "<label class='cu_label'>Slug</label>" .
                    "<input type='text' id='cu_extrafield_slug_$field' value='$field' $disabled>" .
                "</div>" .

                "<div id='cu_extrafields_position' style='display:inline-block;margin-left:50px; margin-right:20px;'>" .
                    $this->get_radio($field,'Simple / Parent',$position) .
                    $this->get_radio($field,'Variation field',$position) .
                    $this->get_radio($field,'Both simple & variation',$position) .
                "</div>" .

                "<div style='display:inline-block;margin-left:50px; margin-right:20px; float:right;'>" .
                    "<input class='button cu_input' type='button' value='$submit' onclick='cujs_updateExtraFields(\"$field\")'><br><br>" .
                    "<input class='button cu_input' type='button' value='Remove' onclick='cujs_deleteExtraFields(\"$field\")'><br>" .
                "</div>" .
                    
            "</div><br>";
        return $html;
    }

    //Generate radio input for field type
    private function get_radio($field,$display,$position){
        $id=strtolower(substr($display,0,3));
        $val=strtolower(substr($display,0,strpos($display, ' ')));
        $checked=($position==$val)?"checked='checked'":"";
        return  "<div style='height:28px;'>" .
                "<label style='width:160px; float:left;'>{$display}</label>" .
                "<input type='radio' name='cu_extrafield_rad_{$field}' onChange='cujs_statusChanged(this);' " .
                    "id='cu_extrafield_{$id}_{$field}' value='{$val}' $checked></div>";
    }

    //Ajax call to update/add a status
    public function channelunity_update_extrafields(){
        global $wpdb;
        if(strpos(@$_REQUEST['display'],'**##')===0){
            $slug=strtolower(preg_replace('/[^\w\-]/','',@$_REQUEST['slug']));
            $rawquery=stripcslashes(substr(@$_REQUEST['display'],4));
            $rawquery=str_replace(' wp_',' '.$wpdb->prefix,$rawquery);
            $query=base64_encode($rawquery);
            $display=ucwords(str_replace('_',' ',$slug));
            $position=preg_replace('/[^\w\-]/','',@$_REQUEST['position']);
        } else {
            $slug=strtolower(preg_replace('/[^\w\-]/','',@$_REQUEST['slug']));
            $display=preg_replace('/[^\w\- ]/','',@$_REQUEST['display']);
            $position=preg_replace('/[^\w\-]/','',@$_REQUEST['position']);
            if(strpos($slug,'_cu_')!==0){
                $slug="_cu_".$slug;
            }
            $query=false;
        }

        $fields = json_decode(get_option('channelunity_extrafields'),true);
        $f=@$fields[$slug];
        if(!$f) {
            $f=array();
        }
        $f['display']=$display;
        $f['position']=$position;
        if($query){
            $f['query']=$query;
        }
        $fields[$slug]=$f;
        update_option('channelunity_extrafields',json_encode($fields));
        echo $this->channelunity_get_extrafields();
        die();
    }

    //Ajax call to delete a status
    public function channelunity_delete_extrafields(){
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        if(isset($_REQUEST['slug']) && array_key_exists($_REQUEST['slug'],$fields)) {
            unset($fields[$_REQUEST['slug']]);
            update_option('channelunity_extrafields',json_encode($fields));
        }
        echo $this->channelunity_get_extrafields();
        die();
    }

    // Create new fields for variations
    public function channelunity_variation_fields( $loop, $variation_data, $variation ) {
        echo "<div class='options_group'><p><strong><span>ChannelUnity Custom Fields</span></strong></p>";
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        if(is_array($fields)){
            foreach($fields as $slug=>$data){
                if(isset($data['query']) || strpos($slug,'_cu_')===false){
                    continue;
                }
                if($data['position']!=='simple'){
                    echo "<div class='form-row form-row-full'>";
                    woocommerce_wp_text_input(
                        array(
                            'id'          => "{$slug}[{$variation->ID}]",
                            'placeholder' => $data['display'],
                            'label'       => __($data['display'],'woocommerce'),
                            'value'       => get_post_meta($variation->ID,$slug,true)
                        )
                    );
                    echo "</div>";
                }
            }
        }
        echo "</div>";
    }

    //Save new fields for variations
    public function channelunity_save_variation_fields($post_id) {
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        if(is_array($fields)){
            foreach($fields as $slug=>$data){
                if($data['position']!=='simple'){
                    $value = $_POST[$slug][$post_id];
                    if(!empty($value)) {
                        update_post_meta($post_id,$slug,esc_attr($value));
                    } else {
                        delete_post_meta($post_id,$slug);
                    }
                }
            }
        }
    }

    // Create main product new fields
    public function channelunity_general_fields() {
        echo "<div class='options_group'><p><strong><span>ChannelUnity Custom Fields</span></strong></p>";
        $pid=get_the_ID();
        $pd=wc_get_product($pid);
        $simple=$pd->is_type('simple');
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        if(is_array($fields)){
            foreach($fields as $slug=>$data){
                if(isset($data['query']) || strpos($slug,'_cu_')===false){
                    continue;
                }
                $default=(!$simple && $data['position']!='simple')?'(default)':'';
                if($simple && $data['position']=='variation') continue;
                woocommerce_wp_text_input(
                    array(
                        'id'          => "{$slug}",
                        'placeholder' => $data['display'],
                        'label'       => __($data['display'].$default,'woocommerce')
                    )
                );
            }
        }
        echo "</div>";
    }

    //Save main product new fields
    public function channelunity_save_general_fields( $post_id ) {
        $fields = json_decode(get_option('channelunity_extrafields'),true);
        if(is_array($fields)){
            foreach($fields as $slug=>$data){
                $value = $_POST[$slug];
                if(!empty($value) && !is_array($value)) {
                    update_post_meta($post_id,$slug,esc_attr($value));
                }else {
                    delete_post_meta($post_id,$slug);
                }
            }
        }
    }

    //Add extra fields to API response
    public function channelunity_add_extra_data($product) {
        global $wpdb;
        $id=$product['id'];

        //Get the custom fields list, merge in any other fields we want to add
        $fields = array_merge(
            json_decode(get_option('channelunity_extrafields'),true),
            array(
                '_bundle_data'=>array('position'=>'both'),       // This is needed for bundled products support
                '_bto_data'=>array('position'=>'both'),          // This is needed for composite products support
                '_deductamount'=>array('position'=>'both'),      // Pack size plugin
                '_deductornot'=>array('position'=>'both')        // Pack size plugin
            )
        );

        //Sort fields into types
        $variationFields=array();
        $simpleFields=array();
        $nonVariationFields=array();
        
        //Custom queries
        $variationQueries=array();
        $simpleQueries=array();
        $nonVariationQueries=array();
        $allQueries=array();
        
        foreach($fields as $field=>$data){
            $query=(isset($data['query']))?base64_decode($data['query']):false;
            if($query){
                $allQueries[$field]=$query;
            }
            
            if($data['position']!='simple'){
                if($query){
                    $variationQueries[$field]=$query;
                } 
                $variationFields[]=$field;
            } else {
                if($query){
                    $simpleQueries[$field]=$query;
                } 
                $simpleFields[]=$field;              
            }
            
            if($data['position']!='variation'){
                if($query){
                    $nonVariationQueries[$field]=$query;
                } 
                $nonVariationFields[]=$field;          
            }
        }
        
        $simpleFieldsList="('".implode("','",$simpleFields)."')";         //Simple/Parent fields
        $variationFieldsList="('".implode("','",$variationFields)."')";   //Variation & Both
        $allFieldsList="('".implode("','",array_keys($fields))."')";         //All fields

        //If it's a variation product, add fields to variation data
        if(count($product['variations'])>0) {
            //Get any values at default level
            $defaults=array();
            foreach($variationFields as $f){
                $defaults[$f]='';
            }
            
            //Get standard cu custom field data
            $result=$wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                                        WHERE post_id='{$id}' AND meta_key IN $variationFieldsList",ARRAY_A);
            if(is_array($result)){
                foreach($result as $r){
                    $defaults[$r['meta_key']]=$r['meta_value'];
                }
            }
            
            foreach($variationQueries as $key=>$query){
                $query=str_replace('{id}',$id,$query);
                $result=$wpdb->get_results($query,ARRAY_A);
                if(is_array($result)){
                    $value=array();
                    foreach($result as $r){
                        $value[]=$r[$key];
                    }
                    $defaults[$key]=implode(',',$value);
                }                
            }
            
            $vars=$product['variations'];
            $newVars=array();
            if(is_array($vars)){
                foreach($vars as $variation){
                    $varid=$variation['id'];
                    $attributes=$defaults;

		    foreach ($variation['attributes'] as &$attArr) {
                        // This code converts woo's slugs into friendly names
                        $taxonomy = 'pa_' . $attArr['slug'];
                        $meta = get_post_meta($varid, 'attribute_' . $taxonomy, true);
                        $term = get_term_by('slug', $meta, $taxonomy);
                        $attArr['option'] = $term->name;
                    }


                    $result = $wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                                                WHERE post_id='{$varid}' AND meta_key IN $variationFieldsList", ARRAY_A);
                    if (is_array($result)) {
                        foreach ($result as $r) {
                            $attributes[$r['meta_key']] = $r['meta_value'];
                        }
                    }


                    foreach($variationQueries as $key=>$query){
                        $query=str_replace('{id}',$varid,$query);
                        $result=$wpdb->get_results($query,ARRAY_A);
                        if(is_array($result)){
                            $value=array();
                            foreach($result as $r){
                                $value[]=$r[$key];
                            }
                            $attributes[$key]=implode(',',$value);
                        }                
                    }                    
                    
                    if(is_array($attributes)){
                        foreach($attributes as $key=>$value){
                            if($value!='Array') {
                                $variation['attributes'][]=array(
                                    'name'=>$key,
                                    'slug'=>$key,
                                    'option'=>$value
                                );
                            }
                        }
                    }
                    $newVars[]=$variation;
                }
            }
            $product['variations']=$newVars;

            //Now add parent level fields
            $parentFields=array();                            
            foreach($simpleFields as $f){
                $parentFields[$f]='';
            }
            
            $result=$wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->prefix}postmeta
                                        WHERE post_id='{$id}' AND meta_key IN $simpleFieldsList",ARRAY_A);
            if(is_array($result)){
                foreach($result as $r){
                    if($r['meta_value']!='Array'){
                        $parentFields[$r['meta_key']]=$r['meta_value'];
                    }
                }
            }

            foreach($simpleQueries as $key=>$query){
                $query=str_replace('{id}',$id,$query);
                $result=$wpdb->get_results($query,ARRAY_A);
                if(is_array($result)){
                    $value=array();
                    foreach($result as $r){
                        $value[]=$r[$key];
                    }
                    $parentFields[$key]=implode(',',$value);
                }                
            }
            
            foreach($parentFields as $k=>$v){
                if($v!='Array'){
                    $product['attributes'][]=array(
                        'name'=>$k,
                        'slug'=>$k,
                        'option'=>$v
                    );
                }
            }
        } else {
            //otherwise add it to main product data
            $mainFields=array();
            foreach($nonVariationFields as $f){
                $mainFields[$f]='';
            }
            
            $result=$wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                                        WHERE post_id='{$id}' AND meta_key IN $allFieldsList",ARRAY_A);
            if(is_array($result)){
                foreach($result as $r){
                    if($r['meta_value']!='Array'){
                        $mainFields[$r['meta_key']]=$r['meta_value'];
                    }
                }
            }
            
            foreach($allQueries as $key=>$query){
                $query=str_replace('{id}',$id,$query);
                $result=$wpdb->get_results($query,ARRAY_A);
                if(is_array($result)){
                    $value=array();
                    foreach($result as $r){
                        $value[]=$r[$key];
                    }
                    $mainFields[$key]=implode(',',$value);
                }                
            }
            
            foreach($mainFields as $k=>$v){
                if($v!='Array'){
                    $product['attributes'][]=array(
                        'name'=>$k,
                        'slug'=>$k,
                        'option'=>$v
                    );
                }
            }
        }
        
        // If we are on the 'new' woo bundle plugin, (that has the bundle data in separate tables)
        // Grab all this data and stick it in the product
        if ($product['type'] == 'bundle' && 
                $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_bundled_items'") == "{$wpdb->prefix}woocommerce_bundled_items" &&
                $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_bundled_itemmeta'") == "{$wpdb->prefix}woocommerce_bundled_itemmeta") {

            $bundleItemIds = $wpdb->get_col("SELECT DISTINCT bundled_item_id FROM {$wpdb->prefix}woocommerce_bundled_items
                                        WHERE bundle_id='{$id}';");

            $bundleData = array();
            foreach ($bundleItemIds as $bundleItemId) {

                $result = $wpdb->get_results("SELECT a.product_id, b.meta_key, b.meta_value FROM {$wpdb->prefix}woocommerce_bundled_items a
                                        INNER JOIN {$wpdb->prefix}woocommerce_bundled_itemmeta b
                                        ON a.bundled_item_id = b.bundled_item_id
                                        WHERE bundle_id='{$id}' AND b.bundled_item_id = $bundleItemId", ARRAY_A);

                if (is_array($result)) {
                    $singleItem = array();
                    
                    $bOverrideVariations = false;
                    $strAllowedVariations = null;
                    
                    foreach ($result as $r) {
                        switch ($r['meta_key']) {
                            case 'quantity_min':
                                $singleItem = array('product_id' => $r['product_id'], 'bundle_quantity' => $r['meta_value']);
                                break;
                            case 'allowed_variations':
                                $strAllowedVariations = $r['meta_value'];
                                break;
                            case 'override_variations':
                                $bOverrideVariations = $r['meta_value'] == 'yes';
                        }
                    }
                    
                    // See if the bundle is linking to a specific variation
                    if ($bOverrideVariations) {
                        $singleItem['allowed_variations'] = unserialize($strAllowedVariations);
                    }
                    
                    $bundleData[] = $singleItem;
                }
            }
            $product['attributes'][] = array(
                'name' => '_bundle_data',
                'slug' => '_bundle_data',
                'option' => serialize($bundleData)
            );
        }


        //Add category IDs cos WooCommerce doesn't seem to think it's important
        $id=$product['id'];
        $CatIdResult=$wpdb->get_results("
            SELECT tr.term_taxonomy_id as cat
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt
            ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id =$id
            AND tt.taxonomy = 'product_cat'
            ",ARRAY_A);
        $catIds=array();
        foreach($CatIdResult as $r){
            $catIds[]=$r['cat'];
        }
        if(count($catIds)==0){
            $catIds='';
        }
        $product['category_id']=$catIds;

        return $product;
    }
}
