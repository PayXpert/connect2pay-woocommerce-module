<script type="text/javascript">
    jQuery(document).ready(function ($) {

        $(document.body).on('updated_checkout', function () {
            var gettokken = $("#tokenpass").val();
            var version = $("#seamless_version").val();
            var hash = $("#seamless_hash").val();
            $("#payxpert").remove();
            var sNew = document.createElement("script");
            sNew.async = true;
            sNew.src = "https://connect2.payxpert.com/payment/" + gettokken + "/connect2pay-seamless-v" + version + ".js";
            sNew.setAttribute('data-mount-in', "#payment-container");
            sNew.setAttribute('id', "payxpert");
            sNew.setAttribute('integrity', hash);
            sNew.setAttribute('crossorigin', "anonymous");
            var s0 = document.getElementsByTagName('script')[0];
            s0.parentNode.insertBefore(sNew, s0);

            // Deprecated
            /*
            if ($("#billing_first_name").val().length === 0 || $("#billing_first_name").val().length === 0 || $("#billing_address_1").val().length === 0 || $("#billing_country").val().length === 0 || $("#billing_city").val().length === 0 || $("#billing_postcode").val().length === 0 || $("#billing_phone").val().length === 0 || $("#billing_email").val().length === 0) {
                $("#payment-container").hide();
                $("#error-message-seamless").show();
            } else {
                $("#payment-container").show();
                $("#error-message-seamless").hide();
            }
            */

            $("#payment-container").show();
            $("#error-message-seamless").hide();

        });

        // On Change Payment Method Check
        $(document).on('change', 'input[name="payment_method"]', function () {
            var getpaymentvalue = $(this).val();
            if (getpaymentvalue == 'payxpert_seamless') {
                $("#place_order").attr("disabled", "disabled");
            } else {
                $("#place_order").removeAttr("disabled");
            }
        });


        // On Page Load
        $(window).on("load", function () {
            $('input[value="payxpert_seamless"]').trigger('click');
        });

        // check if all of fill
        $(document).on('change', '.woocommerce input', function () {
            
            // Deprecated
            /*if ($("#billing_first_name").val().length === 0 || $("#billing_first_name").val().length === 0 || $("#billing_address_1").val().length === 0 || $("#billing_country").val().length === 0 || $("#billing_city").val().length === 0 || $("#billing_postcode").val().length === 0 || $("#billing_phone").val().length === 0 || $("#billing_email").val().length === 0) {
                $("#payment-container").hide();
                $("#error-message-seamless").show();
            } else {
                $("#payment-container").show();
                $("#error-message-seamless").hide();
            }*/

            $("#payment-container").show();
            $("#error-message-seamless").hide();
        });
    });

    function callbackreturn(response) {
        if (response.transaction.resultCode == '000') {
            document.getElementById("transactionId").value = response.transaction.transactionID;
            document.getElementById("paymentId").value = response.transaction.paymentID;
            document.getElementById("paymentstatus").value = response.transaction.resultCode;
            var proceedOrder = document.getElementById('place_order');
            proceedOrder.click();
        }
        // console.log(response);
    }
</script>