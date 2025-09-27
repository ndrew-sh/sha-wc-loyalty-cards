jQuery(document).ready(function($){
    $('#wclc-generate-btn').on('click', function(e){
        e.preventDefault();

        let amount = parseInt($('#wclc_amount').val(), 10);
        let cardLength = parseInt($('#wclc_card_length').val(), 10);
        let rule = $('#wclc_generation_rule').val();
        let offset = 0;

        $('#wclc-import-progress').show();
        $('#wclc-progress-bar').val(0).attr('max', amount);
        $('#wclc-notices').empty();

        function showNotice(message, type = 'success') {
            let cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
            let $notice = $(
                '<div class="notice ' + cssClass + '">' +
                    '<p>' + message + '</p>' +
                '</div>'
            );
            $('#wclc-notices').append($notice);
        }

        function processBatch() {
            $.post(wclc.ajax_url, {
                action: 'wclc_generate_cards',
                [wclc.prefix + 'nonce']: wclc.generation_nonce,
                amount: amount,
                card_length: cardLength,
                generation_rule: rule,
                offset: offset
            }, function(res){
                if(res.success){
                    offset = res.data.total;
                    $('#wclc-import-progress').show()
                    $('#wclc-progress-bar').val(offset);

                    if(!res.data.done){
                        processBatch();
                    } else {
                        $('#wclc-import-progress').hide();
                        showNotice('Generation completed. Created ' + offset + ' card(s).', 'success');
                    }
                } else {
                    showNotice('Error: ' + res.data.message, 'error');
                    $('#wclc-import-progress').hide();
                }
            });
        }

        processBatch();
    });

    $('#wclc_csv_file').on('change', function() {
        $('#wclc_import_btn').prop('disabled', !this.files.length);
    });

    $('#wclc-import-form').on('submit', function(e){
        e.preventDefault();

        var formData = new FormData(this);

        $('#wclc-import-progress').show();
        $('#wclc-progress-bar').val(0);
        $('#wclc-notices').empty();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response){
                if(response.success){
                    var tmp_file = response.data.tmp_file;
                    var total_rows = response.data.total_rows;
                    var batch_size = response.data.batch_size;
                    var processed = 0;

                    $('#wclc-progress-bar').attr('max', Math.ceil(total_rows / batch_size));

                    var totals = { inserted:0, updated:0, skipped:0 };

                    function processBatch(batch_index){
                        $.post(ajaxurl, {
                            action: 'wclc_process_csv_file',
                            [wclc.prefix + 'import_nonce']: wclc.import_nonce,
                            tmp_file: tmp_file,
                            batch_index: batch_index,
                        }, function(res){
                            if(res.success && res.data){
                                totals.inserted += res.data.inserted;
                                totals.updated  += res.data.updated;
                                totals.skipped  += res.data.skipped;

                                processed++;

                                $('#wclc-progress-bar').val(processed);

                                if(res.data.finished){
                                    $.post(ajaxurl, {
                                        action: 'wclc_delete_tmp_file',
                                        tmp_file: tmp_file,
                                        [wclc.prefix + 'import_nonce']: wclc.import_nonce
                                    });

                                    $('#wclc-import-progress').hide();

                                    $('#wclc-notices').html(
                                        '<div class="notice notice-success"><p>' +
                                        'Import finished: ' +
                                        totals.inserted + ' inserted, ' +
                                        totals.updated + ' updated, ' +
                                        totals.skipped + ' skipped' +
                                        '</p></div>'
                                    );
                                } else {
                                    processBatch(batch_index + 1);
                                }
                            } else {
                                alert('Error processing batch');
                            }
                        });
                    }

                    processBatch(0);
                } else {
                    alert('Error uploading file: ' + response.data);
                    $('#wclc-import-progress').hide();
                    $('#wclc-progress-bar').val(0);
                }
            }
        });
    });
});
