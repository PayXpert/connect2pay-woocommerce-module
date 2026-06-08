jQuery(document).ready(function($){
    // Init dialog
    $("#payxpert-support-dialog").dialog({
        autoOpen: false,
        modal: true,
        width: 500,
        buttons: [
            {
                text: payxpert_support.i18n.send,
                class: "button-primary",
                click: function() {
                    // Simple validation
                    let valid = true;
                    $("#payxpert-support-form [required]").each(function(){
                        if (!$(this).val()) {
                            valid = false;
                            $(this).css("border","1px solid red");
                        } else {
                            $(this).css("border","");
                        }
                    });
                    if (!valid) {
                        return;
                    }

                    // Collect form values.
                    var data = {
                        action: "payxpert_send_support_email",
                        firstname: $("#support_firstname").val(),
                        lastname: $("#support_lastname").val(),
                        email: $("#support_email").val(),
                        subject: $("#support_subject").val(),
                        _ajax_nonce: payxpert_support.nonce // nonce for AJAX
                    };

                    // Disable submit while waiting.
                    $(".ui-dialog-buttonpane button:contains('Envoyer')").prop("disabled", true);

                    // Show loading state.
                    $(".payxpert-support-response").html('<span class="payxpert-spinner"></span>');

                    // POST to admin-ajax.
                    $.post(payxpert_support.ajax_url, data, function(response){
                        if(response.success){
                            $(".payxpert-support-response").html("<div class='payxpert-notice notice notice-success'><p>"+response.data+"</p></div>");
                            $("#payxpert-support-form")[0].reset();
                        } else {
                            $(".payxpert-support-response").html("<div class='payxpert-notice notice notice-error'><p>"+response.data+"</p></div>");
                        }
                        $(".ui-dialog-buttonpane button:contains('Envoyer')").prop("disabled", false);
                    });
                }
            },
            {
                text: payxpert_support.i18n.close,
                click: function() {
                    $(this).dialog("close");
                    $(".response-message").hide();
                    $("#payxpert-support-form")[0].reset();
                },
            },
        ]
    });

    // Open dialog on button click.
    $("#open-payxpert-support").on("click", function(e){
        e.preventDefault();
        $("#payxpert-support-dialog").dialog("open");
    });

    $('img[data-target]:not([data-target=""])').on('click', function() {
        const url = $(this).data('target');
        window.open(url, '_blank');
    });

    function toggleCaptureModeDesc() {
        var $select = jQuery('select[name="payxpert_capture_mode"]');
        var $desc = $select.parents('.form-group').find('.description');

        if ($select.val() == 1) {
            $desc.show();
        } else {
            $desc.hide();
        }
    }

    function initCaptureMode() {
        toggleCaptureModeDesc();
        jQuery('select[name="payxpert_capture_mode"]').on('change', toggleCaptureModeDesc);
    }

    function toggleInstalmentFields() {
        var anyActive = false;

        // Hide all instalment fieldsets.
        $('fieldset[id^="payxpert_installment_x"]').hide();

        // Show fieldset for each enabled instalment plan.
        $('input[type="checkbox"][id^="payxpert_installment_x"][id$="_enabled"]').each(function () {
            var checkbox = $(this);
            var idMatch = checkbox.attr('id').match(/^payxpert_installment_x(\d+)_enabled$/);
            if (idMatch && checkbox.is(':checked')) {
                var xNum = idMatch[1];
                $('#payxpert_installment_x' + xNum).show();
                anyActive = true;
            }
        });

        // Toggle whole instalment settings block.
        $('#installment_settings').toggle(anyActive);
    }

    function initInstalments() {
        toggleInstalmentFields();
        $('input[type="checkbox"][id^="payxpert_installment_x"][id$="_enabled"]').on('change', toggleInstalmentFields);
    }

    // === Page init ===
    initCaptureMode();
    initInstalments();
});
