/**
 * WooSync Pro - Admin JavaScript
 */

(function($) {
    'use strict';

    let fetchedProducts = [];
    let selectedIndices = [];
    let isSyncing = false;
    let syncCounters = { created: 0, updated: 0, failed: 0, variations: 0 };

    $(document).ready(function() {
        initSourceSelector();
        initFetchProducts();
        initTableControls();
        initSearch();
        initSyncActions();
    });

    /**
     * Source Selector Tab Switching
     */
    function initSourceSelector() {
        $('.source-option').on('click', function() {
            $('.source-option').removeClass('active');
            $(this).addClass('active');

            const targetId = $(this).data('target');
            $('.source-panel').hide();
            $('#' + targetId).show();

            $('#fetch-alert').hide().text('');
        });
    }

    /**
     * Fetch Products via AJAX
     */
    function initFetchProducts() {
        $('#btn-fetch-products').on('click', function(e) {
            e.preventDefault();
            if (isSyncing) return;

            const apiType = $('input[name="api_type"]:checked').val();
            const config = {
                api_type: apiType,
                shopify_url: $('#shopify_url').val().trim(),
                admin_store_url: $('#admin_store_url').val().trim(),
                admin_access_token: $('#admin_access_token').val().trim(),
                admin_api_version: $('#admin_api_version').val().trim(),
                wp_store_url: $('#wp_store_url').val().trim(),
                wp_consumer_key: $('#wp_consumer_key').val().trim(),
                wp_consumer_secret: $('#wp_consumer_secret').val().trim()
            };

            // Client-side validation
            if (apiType === 'shopify_json' && !config.shopify_url) {
                showAlert('Please enter a Shopify store URL or products.json URL.', 'error');
                return;
            }
            if (apiType === 'shopify_admin' && (!config.admin_store_url || !config.admin_access_token)) {
                showAlert('Please enter both Shopify Store Domain and Admin Access Token.', 'error');
                return;
            }
            if (apiType === 'wordpress_wc' && !config.wp_store_url) {
                showAlert('Please enter the remote WordPress / WooCommerce site URL.', 'error');
                return;
            }

            hideAlert();
            const $btn = $('#btn-fetch-products');
            const $spinner = $('#fetch-spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: woosync_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'woosync_fetch_products',
                    nonce: woosync_vars.nonce,
                    config: config
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');

                    if (response.success && Array.isArray(response.data) && response.data.length > 0) {
                        fetchedProducts = response.data;
                        $('#card-product-list').slideDown();
                        renderTable();
                        showAlert(`Successfully fetched ${fetchedProducts.length} products!`, 'success');
                    } else {
                        const errMsg = response.data || 'No products returned from API.';
                        showAlert(errMsg, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    showAlert(woosync_vars.i18n.server_error, 'error');
                }
            });
        });
    }

    /**
     * Render Products in Table
     */
    function renderTable() {
        const $tbody = $('#woosync-table-body');
        const searchQuery = $('#product-search').val().toLowerCase().trim();
        $tbody.empty();

        let visibleCount = 0;
        let syncedCount = 0;

        fetchedProducts.forEach(function(product, index) {
            const titleMatch = product.title && product.title.toLowerCase().indexOf(searchQuery) !== -1;
            const skuMatch = product.sku && product.sku.toLowerCase().indexOf(searchQuery) !== -1;
            const sourceIdMatch = product.source_id && String(product.source_id).indexOf(searchQuery) !== -1;

            if (searchQuery && !titleMatch && !skuMatch && !sourceIdMatch) {
                return;
            }

            visibleCount++;
            if (product.is_synced) syncedCount++;

            // Image
            let imgHtml = '<span class="product-thumb-placeholder">No Image</span>';
            if (product.images && product.images.length > 0 && product.images[0].src) {
                imgHtml = `<img src="${escapeHtml(product.images[0].src)}" class="product-thumb-img" alt="${escapeHtml(product.title)}">`;
            }

            // Pricing
            let priceHtml = '$0.00';
            if (product.price_display) {
                priceHtml = escapeHtml(product.price_display);
            } else if (product.sale_price) {
                priceHtml = `<del>$${escapeHtml(product.regular_price)}</del> <ins style="color:#d63638; text-decoration:none; font-weight:bold;">$${escapeHtml(product.sale_price)}</ins>`;
            } else if (product.regular_price || product.price) {
                priceHtml = `$${escapeHtml(product.regular_price || product.price)}`;
            }

            // Options & Variant badges
            let variantBadgeHtml = '';
            if (product.has_variants) {
                const varCount = product.variant_count || (product.variants ? product.variants.length : 0);
                variantBadgeHtml += `<span class="badge-variant" title="${varCount} variations">${varCount} Variants</span> `;
            }

            let optionsHtml = '';
            if (product.options && product.options.length > 0) {
                const optionNames = product.options.map(function(o) { return o.name; }).join(', ');
                if (optionNames && optionNames.toLowerCase() !== 'title') {
                    optionsHtml = `<span class="product-options-meta">(${escapeHtml(optionNames)})</span>`;
                }
            }

            // Status Badge
            let statusBadge = `<span class="woosync-badge badge-new" id="badge-${index}">${woosync_vars.i18n.status_new}</span>`;
            if (product.is_synced && product.local_product_id) {
                const editLink = product.local_edit_url || `post.php?post=${product.local_product_id}&action=edit`;
                statusBadge = `<a href="${escapeHtml(editLink)}" target="_blank" class="woosync-badge badge-synced" id="badge-${index}" title="Click to edit in WooCommerce">✓ Synced (#${product.local_product_id})</a>`;
            }

            // Row HTML
            const rowHtml = `
                <tr id="row-${index}">
                    <td class="col-cb check-column">
                        <input type="checkbox" class="product-item-cb" value="${index}" ${!product.is_synced ? 'checked' : ''}>
                    </td>
                    <td class="col-thumb">${imgHtml}</td>
                    <td class="col-title">
                        <div class="product-title-text">${escapeHtml(product.title)}</div>
                        <div class="product-meta-row">
                            ${variantBadgeHtml}
                            ${optionsHtml}
                            ${product.vendor ? `<span class="product-vendor-meta">Vendor: ${escapeHtml(product.vendor)}</span>` : ''}
                        </div>
                    </td>
                    <td class="col-source"><code>#${escapeHtml(product.source_id)}</code></td>
                    <td class="col-sku">${product.sku ? `<code>${escapeHtml(product.sku)}</code>` : '<span style="color:#a7aaad;">N/A</span>'}</td>
                    <td class="col-price">${priceHtml}</td>
                    <td class="col-status">${statusBadge}</td>
                    <td class="col-action">
                        <button type="button" class="button button-small btn-single-sync" data-index="${index}">
                            ${product.is_synced ? woosync_vars.i18n.btn_resync : woosync_vars.i18n.btn_sync_now}
                        </button>
                    </td>
                </tr>
            `;

            $tbody.append(rowHtml);
        });

        updateSummaryStats();
    }

    /**
     * Search and Filter
     */
    function initSearch() {
        let debounceTimer;
        $('#product-search').on('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                renderTable();
            }, 250);
        });
    }

    /**
     * Table selection controls
     */
    function initTableControls() {
        // Select All / Deselect All checkbox in thead
        $('#select-all-cb').on('change', function() {
            const isChecked = $(this).prop('checked');
            $('.product-item-cb').prop('checked', isChecked);
            updateSummaryStats();
        });

        // Individual item checkbox
        $(document).on('change', '.product-item-cb', function() {
            updateSummaryStats();
        });

        // Select Unsynced button
        $('#btn-select-unsynced').on('click', function(e) {
            e.preventDefault();
            $('.product-item-cb').each(function() {
                const idx = $(this).val();
                $(this).prop('checked', !fetchedProducts[idx].is_synced);
            });
            updateSummaryStats();
        });

        // Deselect All button
        $('#btn-deselect-all').on('click', function(e) {
            e.preventDefault();
            $('.product-item-cb').prop('checked', false);
            $('#select-all-cb').prop('checked', false);
            updateSummaryStats();
        });
    }

    /**
     * Update counter summary
     */
    function updateSummaryStats() {
        const total = fetchedProducts.length;
        const selected = $('.product-item-cb:checked').length;
        $('#stats-summary').text(`Showing ${total} items (${selected} selected for sync)`);
    }

    /**
     * Sync Actions (Single & Batch)
     */
    function initSyncActions() {
        // Single Sync Button
        $(document).on('click', '.btn-single-sync', function(e) {
            e.preventDefault();
            if (isSyncing) return;

            const $btn = $(this);
            const index = $btn.data('index');
            const product = fetchedProducts[index];
            if (!product) return;

            $btn.prop('disabled', true).text('Syncing...');
            
            syncSingleProduct(product, function(res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    product.is_synced = true;
                    product.local_product_id = res.product_id;
                    product.local_edit_url = res.edit_url;
                    
                    const actionLabel = res.action === 'updated' ? 'Updated' : 'Synced';
                    const badgeClass = res.action === 'updated' ? 'badge-updated' : 'badge-synced';
                    $(`#badge-${index}`).replaceWith(
                        `<a href="${res.edit_url}" target="_blank" class="woosync-badge ${badgeClass}" id="badge-${index}" title="Click to edit">✓ ${actionLabel} (#${res.product_id})</a>`
                    );
                    $btn.text(woosync_vars.i18n.btn_resync);
                } else {
                    $(`#badge-${index}`).replaceWith(
                        `<span class="woosync-badge badge-error" id="badge-${index}">Failed</span>`
                    );
                    $btn.text(woosync_vars.i18n.btn_sync_now);
                    alert('Sync error: ' + res.error);
                }
            });
        });

        // Batch Sync Button
        $('#btn-sync-selected').on('click', function(e) {
            e.preventDefault();
            if (isSyncing) return;

            selectedIndices = [];
            $('.product-item-cb:checked').each(function() {
                selectedIndices.push(parseInt($(this).val(), 10));
            });

            if (selectedIndices.length === 0) {
                alert(woosync_vars.i18n.select_at_least);
                return;
            }

            if (!confirm(woosync_vars.i18n.confirm_bulk)) {
                return;
            }

            // Start batch sync
            isSyncing = true;
            syncCounters = { created: 0, updated: 0, failed: 0, variations: 0 };
            
            $('#btn-sync-selected, #btn-fetch-products, #btn-select-unsynced, #btn-deselect-all, .btn-single-sync, .product-item-cb, #select-all-cb').prop('disabled', true);
            $('#sync-progress-wrap').slideDown();
            $('#woosync-log-box').slideDown();
            $('#woosync-log-list').empty();

            processBatchQueue(0);
        });
    }

    /**
     * Process Batch Queue recursively
     */
    function processBatchQueue(currentIndex) {
        const total = selectedIndices.length;

        if (currentIndex >= total) {
            // Completed
            isSyncing = false;
            $('#progress-bar-inner').css('width', '100%');
            $('#progress-percentage-text').text('100%');
            
            const msg = woosync_vars.i18n.sync_complete
                .replace('%1$d', total)
                .replace('%2$d', syncCounters.created)
                .replace('%3$d', syncCounters.updated)
                .replace('%4$d', syncCounters.failed);
            
            $('#progress-status-text').text(msg + (syncCounters.variations > 0 ? ` (Total ${syncCounters.variations} variations synced)` : ''));
            
            $('#btn-sync-selected, #btn-fetch-products, #btn-select-unsynced, #btn-deselect-all, .btn-single-sync, .product-item-cb, #select-all-cb').prop('disabled', false);
            updateSummaryStats();
            return;
        }

        const productIndex = selectedIndices[currentIndex];
        const product = fetchedProducts[productIndex];

        // Update progress UI
        const percent = Math.round((currentIndex / total) * 100);
        $('#progress-bar-inner').css('width', percent + '%');
        $('#progress-percentage-text').text(percent + '%');
        $('#progress-status-text').text(`Syncing ${currentIndex + 1} of ${total}: "${product.title}"...`);

        syncSingleProduct(product, function(res) {
            const timeStr = new Date().toLocaleTimeString();
            let logHtml = '';

            if (res.success) {
                product.is_synced = true;
                product.local_product_id = res.product_id;
                product.local_edit_url = res.edit_url;

                const varInfo = res.variations_synced ? ` [${res.variations_synced} variations]` : '';
                if (res.variations_synced) {
                    syncCounters.variations += res.variations_synced;
                }

                if (res.action === 'updated') {
                    syncCounters.updated++;
                    logHtml = `<li class="log-update">[${timeStr}] ⟳ Updated product: <strong>${escapeHtml(product.title)}</strong>${varInfo} (Woo ID: #${res.product_id}, Source ID: #${product.source_id})</li>`;
                    $(`#badge-${productIndex}`).replaceWith(
                        `<a href="${res.edit_url}" target="_blank" class="woosync-badge badge-updated" id="badge-${productIndex}">✓ Updated (#${res.product_id})</a>`
                    );
                } else {
                    syncCounters.created++;
                    logHtml = `<li class="log-success">[${timeStr}] ✓ Created product: <strong>${escapeHtml(product.title)}</strong>${varInfo} (Woo ID: #${res.product_id}, Source ID: #${product.source_id})</li>`;
                    $(`#badge-${productIndex}`).replaceWith(
                        `<a href="${res.edit_url}" target="_blank" class="woosync-badge badge-synced" id="badge-${productIndex}">✓ Synced (#${res.product_id})</a>`
                    );
                }
            } else {
                syncCounters.failed++;
                logHtml = `<li class="log-error">[${timeStr}] ✗ Failed: <strong>${escapeHtml(product.title)}</strong> - Error: ${escapeHtml(res.error)}</li>`;
                $(`#badge-${productIndex}`).replaceWith(
                    `<span class="woosync-badge badge-error" id="badge-${productIndex}">Failed</span>`
                );
            }

            const $logList = $('#woosync-log-list');
            $logList.append(logHtml);
            $('#woosync-log-box').scrollTop($logList[0].scrollHeight);

            // Move to next product
            processBatchQueue(currentIndex + 1);
        });
    }

    /**
     * AJAX helper to sync single product
     */
    function syncSingleProduct(product, callback) {
        $.ajax({
            url: woosync_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'woosync_sync_single',
                nonce: woosync_vars.nonce,
                product: JSON.stringify(product)
            },
            success: function(response) {
                if (response.success && response.data) {
                    callback({
                        success: true,
                        action: response.data.action,
                        product_id: response.data.product_id,
                        is_variable: response.data.is_variable,
                        variations_synced: response.data.variations_synced || 0,
                        edit_url: response.data.edit_url
                    });
                } else {
                    callback({
                        success: false,
                        error: response.data || 'Unknown error'
                    });
                }
            },
            error: function(xhr, status, error) {
                callback({
                    success: false,
                    error: error || 'Network or server error'
                });
            }
        });
    }

    /**
     * Alert helpers
     */
    function showAlert(message, type) {
        const $alert = $('#fetch-alert');
        $alert.removeClass('alert-error alert-success')
              .addClass('alert-' + type)
              .text(message)
              .fadeIn();
    }

    function hideAlert() {
        $('#fetch-alert').hide().text('');
    }

    /**
     * HTML entity escaping
     */
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

})(jQuery);
