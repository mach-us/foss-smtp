jQuery(document).ready(function($) {
    // Test email functionality
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

    // Email log viewing functionality
    const $emailModal = $('#email-content-modal');
    if ($emailModal.length) {
        $emailModal.dialog({
            autoOpen: false,
            modal: true,
            width: 600,
            height: 500,
            buttons: [
                {
                    text: fossSmtpAdmin.close,
                    click: function() {
                        $(this).dialog('close');
                    }
                }
            ]
        });

        $('.view-email-content').on('click', function(e) {
            e.preventDefault();
            const emailId = $(this).data('email-id');
            
            $.ajax({
                url: fossSmtpAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'foss_smtp_view_email',
                    nonce: fossSmtpAdmin.emailLogNonce,
                    id: emailId
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#modal-date').text(data.date);
                        $('#modal-to').text(data.to);
                        $('#modal-subject').text(data.subject);
                        $('#modal-headers').html(data.headers.replace(/\n/g, '<br>'));
                        $('#modal-message').html(data.message);
                        $('#modal-status').html(
                            data.status === 'success' 
                                ? '<span class="dashicons dashicons-yes" style="color: green;"></span> Success'
                                : '<span class="dashicons dashicons-no" style="color: red;"></span> Failed' +
                                  (data.error ? ': ' + data.error : '')
                        );
                        $emailModal.dialog('open');
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to load email details.');
                }
            });
        });
    }
});
