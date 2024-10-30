jQuery(function(){
    if(php_import.running=='true'){
        jQuery('#cu_imageimport_setup').hide();
        jQuery('#cu_imageimport_status').show();
        cujs_imageimport_process();
    }
});

function cujs_imageimport_count(){
    var fields=jQuery('#cu_imageimport_metafields').val();
    jQuery.ajax({
        url: ajaxurl+'?action=channelunity_imageimport_count',
        type: 'POST',
        data: {fields:fields},
        success: cujs_show_count
    });
}

function cujs_show_count(data){
    var plural=(data==1)?' has':'s have';
    jQuery('#cu_imageimport_count').html("<h3>"+ data + " product" + plural + " data in the above fields");
}

function cujs_imageimport_start(){
    var fields=jQuery('#cu_imageimport_metafields').val();
    jQuery.ajax({
        url: ajaxurl+'?action=channelunity_imageimport_start_process',
        type: 'POST',
        data: {'fields':fields},
        success: cujs_update_imageprocess
    });
    jQuery('#cu_imageimport_setup').slideUp();
    jQuery('#cu_imageimport_status').slideDown();
}

function cujs_imageimport_process(){
    jQuery.ajax({
        url: ajaxurl+'?action=channelunity_imageimport_check_process',
        type: 'POST',
        data: {},
        success: cujs_update_imageprocess
    });    
}

function cujs_imageimport_reset(){
    jQuery.ajax({
        url: ajaxurl+'?action=channelunity_imageimport_reset',
        type: 'POST',
        data: {},
        success: cujs_update_reset_complete
    });    
}

function cujs_update_reset_complete(data){
    var plural=(data==1)?' has':'s have';
    jQuery('#cu_imageimport_count').html("<h3>"+ data + " product" + plural + " been reset");
}

function cujs_update_imageprocess(data){
    if(data=='notfound'){
        jQuery('#cu_imageimport_status').slideUp();        
        jQuery('#cu_imageimport_setup').slideDown();
        jQuery('#cu_imageimport_count').html("<h3>No products have data in the above fields");        
    } else if(data=='complete'){
        jQuery('#cu_imageimport_status').slideUp();
        jQuery('#cu_imageimport_complete').slideDown();
        cujs_check_failed();
    } else {
        jQuery('#cu_imageimport_progress').html(data);
        setTimeout(cujs_imageimport_process,3000);
    }
}

function cujs_check_failed(){
    jQuery.ajax({
        url: ajaxurl+'?action=channelunity_imageimport_failed',
        type: 'POST',
        data: {},
        success: cujs_update_failed_report
    });      
}

function cujs_update_failed_report(data){
    if(data!='none'){
        var report="<table class='cu_table' cellspacing='3' cellpadding='3'><tr><th class='column-columnname'>";
        report+="Product Id</th><th class='column-columnname'>URL data</th></tr>" + data + "</table>";
        jQuery('#cu_imageimport_failed').html("<br>Some images could not be imported. Here is the url data in the columns specified:<br><br>" + report);
    }
}