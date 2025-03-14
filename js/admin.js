jQuery(document).ready(function($) {
    const $testEmailButton = $('#send-test-email');
    const $testEmailInput = $('#test-email-to');
    const $spinner = $('.foss-smtp-test-email .spinner');
    const $logArea = $('#smtp-log');

    $testEmailButton.on('click', function() {
        const emailTo = $testEmailInput.val().trim();
        
        if (!emailTo) {
            alert('Please enter an email address');
            return;
        }

        // Disable button and show spinner
        $testEmailButton.prop('disabled', true);
        $spinner.addClass('is-active');
        
        // Clear previous logs
        $logArea.val('');

        // Show sending message
        $logArea.val(fossSmtpAdmin.sending + '\n');

        $.ajax({
            url: fossSmtpAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'foss_smtp_test',
                _ajax_nonce: fossSmtpAdmin.nonce,
                to: emailTo
            },
            success: function(response) {
                if (response.success) {
                    $logArea.val((response.data.logs || []).join('\n'));
                    alert(response.data.message);
                } else {
                    $logArea.val((response.data.logs || []).join('\n'));
                    alert(response.data.message || fossSmtpAdmin.error);
                }
            },
            error: function() {
                alert(fossSmtpAdmin.error);
            },
            complete: function() {
                // Re-enable button and hide spinner
                $testEmailButton.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
});
