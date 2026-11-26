/**
 * Tệp JavaScript thuần (`public/assets/js/app.js`) — TL Gaming.
 *
 * Vai trò:
 * 1. Điều khiển đóng/mở Drawer Menu danh mục trên thiết bị di động (`#mobileMenuBtn` / `#mobileCategoryDrawer`).
 * 2. Điều khiển đóng/mở Sidebar Bộ lọc sản phẩm trên Tablet/Mobile <= 1024px (`#mobileFilterToggleBtn` / `#mobileFilterCloseBtn` / `#catalogFilterSidebar`).
 * 3. Xử lý bộ lọc Tab nhanh ở phân khu Linh kiện & Màn hình trên Trang chủ (`#componentFilterTabs`).
 * 4. Chuẩn hóa từ khóa tìm kiếm, dự phòng ảnh lỗi (`onerror`), Floating Contact Widget và Accordion.
 */

document.addEventListener('DOMContentLoaded', function () {
    const siteHeader = document.querySelector('.site-header');
    if (siteHeader && 'ResizeObserver' in window) {
        const headerObserver = new ResizeObserver(function () {
            const offset = Math.ceil(siteHeader.getBoundingClientRect().height) + 12;
            document.body.style.setProperty('--store-sticky-offset', offset + 'px');
        });
        headerObserver.observe(siteHeader);
    }

    // 1. Đóng/mở Drawer Menu danh mục trên thiết bị di động (Hamburger + nút Danh mục ở Bottom Nav)
    const mobileDrawer = document.getElementById('mobileCategoryDrawer');
    const mobileDrawerBackdrop = document.getElementById('mobileDrawerBackdrop');
    const mobileDrawerCloseBtn = document.getElementById('mobileDrawerCloseBtn');
    const drawerTriggers = document.querySelectorAll('#mobileMenuBtn, [data-open-mobile-drawer="true"]');

    if (mobileDrawer && mobileDrawerBackdrop && mobileDrawerCloseBtn) {
        // Keep this query in sync with the compact header and drawer in style.css.
        const compactHeader = window.matchMedia('(max-width: 960px), (max-width: 1280px) and (hover: none)');
        let drawerOpener = null;

        function setMobileDrawerState(open) {
            if (!open && drawerOpener && drawerOpener.getClientRects().length > 0) {
                drawerOpener.focus({ preventScroll: true });
            }

            mobileDrawer.inert = !open;
            mobileDrawer.classList.toggle('is-open', open);
            mobileDrawer.setAttribute('aria-hidden', String(!open));
            mobileDrawerBackdrop.hidden = !open;
            drawerTriggers.forEach(function (trigger) {
                trigger.setAttribute('aria-expanded', String(open));
            });
            document.body.classList.toggle('mobile-drawer-open', open);

            if (open) {
                mobileDrawerCloseBtn.focus({ preventScroll: true });
            } else {
                drawerOpener = null;
            }
        }

        drawerTriggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                drawerOpener = trigger;
                setMobileDrawerState(true);
            });
        });
        mobileDrawerCloseBtn.addEventListener('click', function () {
            setMobileDrawerState(false);
        });
        mobileDrawerBackdrop.addEventListener('click', function () {
            setMobileDrawerState(false);
        });
        mobileDrawer.addEventListener('click', function (event) {
            if (event.target.closest('a[href]')) {
                setMobileDrawerState(false);
            }
        });
        compactHeader.addEventListener('change', function (event) {
            if (!event.matches && mobileDrawer.classList.contains('is-open')) {
                setMobileDrawerState(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (!mobileDrawer.classList.contains('is-open')) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                setMobileDrawerState(false);
            } else if (event.key === 'Tab') {
                const controls = mobileDrawer.querySelectorAll('a[href], button:not(:disabled)');
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    // 2. Đóng/mở Sidebar Bộ lọc sản phẩm trên Tablet & Mobile (<= 1024px) tại trang /san-pham
    const mobileFilterToggleBtn = document.getElementById('mobileFilterToggleBtn');
    const mobileFilterCloseBtn = document.getElementById('mobileFilterCloseBtn');
    const catalogFilterSidebar = document.getElementById('catalogFilterSidebar');

    if (mobileFilterToggleBtn && mobileFilterCloseBtn && catalogFilterSidebar) {
        function setCatalogFilterState(open, moveFocus = true) {
            catalogFilterSidebar.classList.toggle('is-open', open);
            mobileFilterToggleBtn.setAttribute('aria-expanded', String(open));
            document.body.classList.toggle('catalog-filter-open', open);

            if (moveFocus) {
                const target = open ? catalogFilterSidebar : mobileFilterToggleBtn;
                const control = open ? mobileFilterCloseBtn : mobileFilterToggleBtn;
                target.scrollIntoView({ block: 'start', behavior: 'instant' });
                control.focus({ preventScroll: true });
            }
        }

        mobileFilterToggleBtn.addEventListener('click', function () {
            setCatalogFilterState(!catalogFilterSidebar.classList.contains('is-open'));
        });
        mobileFilterCloseBtn.addEventListener('click', function () {
            setCatalogFilterState(false);
        });
        window.matchMedia('(min-width: 1025px)').addEventListener('change', function (event) {
            if (event.matches) {
                setCatalogFilterState(false, false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !event.defaultPrevented && catalogFilterSidebar.classList.contains('is-open')) {
                event.preventDefault();
                setCatalogFilterState(false);
            }
        });
    }

    // Bộ lọc Tab nhanh tại phân khu Linh kiện & Màn hình trên Trang chủ
    const filterTabsContainer = document.getElementById('componentFilterTabs');
    const componentGrid = document.getElementById('componentProductGrid');

    if (filterTabsContainer && componentGrid) {
        const tabButtons = filterTabsContainer.querySelectorAll('.filter-tab-btn');
        const productCards = componentGrid.querySelectorAll('.product-card');

        tabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const selectedFilter = button.getAttribute('data-filter') || 'all';

                tabButtons.forEach(function (btn) {
                    const active = btn === button;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                productCards.forEach(function (card) {
                    const cardSlug = card.getAttribute('data-category-slug') || '';
                    const shouldShow = (selectedFilter === 'all' || cardSlug === selectedFilter);
                    card.classList.toggle('is-hidden-by-filter', !shouldShow);
                });
            });
        });
    }

    // Cắt khoảng trắng thừa khi gửi biểu mẫu tìm kiếm (Desktop & Mobile)
    const searchForms = document.querySelectorAll('#headerSearchForm, #mobileHeaderSearchForm');
    searchForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const qInput = form.querySelector('input[name="q"]');
            if (qInput) {
                qInput.value = qInput.value.trim();
            }
        });
    });

    // 7. Dự phòng ảnh lỗi (Fallback) cho các thẻ ảnh sản phẩm khi chưa có tệp thực tế hoặc sai đường dẫn
    const productImages = document.querySelectorAll('.product-thumb-img, .spotlight-thumb, .product-detail-img, .main-product-image, .thumb-item-img');
    productImages.forEach(function (img) {
        function applyFallbackImage() {
            if (!img.dataset.fallbackApplied) {
                img.dataset.fallbackApplied = 'true';
                const customFallback = img.getAttribute('data-fallback-src');
                if (customFallback) {
                    img.src = customFallback;
                } else {
                    img.src = img.src.replace(/\/uploads\/products\/[^/]+$/, '/assets/images/placeholder-product.svg');
                }
            }
        }

        img.addEventListener('error', applyFallbackImage);
        if (img.complete && img.naturalWidth === 0) {
            applyFallbackImage();
        }
    });

    // =====================================================================
    // 8. TRANG CHI TIẾT SẢN PHẨM: CHUYỂN ẢNH GALLERY & LIGHTBOX PHÓNG TO
    // =====================================================================
    const mainProductImage = document.getElementById('mainProductImage');
    const mainImageCaption = document.getElementById('mainImageCaption');
    const thumbButtons = Array.from(document.querySelectorAll('#productThumbList .thumb-item-btn'));

    function activateGalleryImageByIndex(targetIndex) {
        if (!mainProductImage || thumbButtons.length === 0) {
            return;
        }

        const safeIndex = ((targetIndex % thumbButtons.length) + thumbButtons.length) % thumbButtons.length;
        const activeBtn = thumbButtons[safeIndex];
        if (!activeBtn) {
            return;
        }

        const newSrc = activeBtn.getAttribute('data-full-src') || activeBtn.getAttribute('data-image-src') || '';
        const newAlt = activeBtn.getAttribute('data-alt') || activeBtn.getAttribute('data-image-alt') || mainProductImage.alt;

        if (newSrc !== '') {
            delete mainProductImage.dataset.fallbackApplied;
            mainProductImage.src = newSrc;
            mainProductImage.alt = newAlt;
            mainProductImage.setAttribute('data-current-index', String(safeIndex));
        }

        if (mainImageCaption) {
            mainImageCaption.textContent = newAlt;
        }

        thumbButtons.forEach(function (btn, idx) {
            const isSelected = idx === safeIndex;
            btn.classList.toggle('is-active', isSelected);
            btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
    }

    if (mainProductImage && thumbButtons.length > 0) {
        thumbButtons.forEach(function (btn, index) {
            btn.addEventListener('click', function () {
                activateGalleryImageByIndex(index);
            });
        });
    }

    // Lightbox phóng to ảnh chính
    const productLightbox = document.getElementById('productLightbox');
    const openLightboxBtn = document.getElementById('openLightboxBtn');
    const lightboxCloseBtn = document.getElementById('lightboxCloseBtn');
    const lightboxBackdrop = document.getElementById('lightboxBackdrop');
    const lightboxImage = document.getElementById('lightboxImage');
    const lightboxCaption = document.getElementById('lightboxCaption');
    const lightboxPrevBtn = document.getElementById('lightboxPrevBtn');
    const lightboxNextBtn = document.getElementById('lightboxNextBtn');

    function syncLightboxWithMainImage() {
        if (!mainProductImage || !lightboxImage) {
            return;
        }
        lightboxImage.src = mainProductImage.src;
        lightboxImage.alt = mainProductImage.alt;
        if (lightboxCaption) {
            lightboxCaption.textContent = mainProductImage.alt;
        }
    }

    function openProductLightbox() {
        if (!productLightbox || !mainProductImage) {
            return;
        }
        syncLightboxWithMainImage();
        productLightbox.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeProductLightbox() {
        if (!productLightbox) {
            return;
        }
        productLightbox.hidden = true;
        document.body.style.overflow = '';
    }

    function stepLightboxImage(delta) {
        if (!mainProductImage || thumbButtons.length <= 1) {
            return;
        }
        const currentIndex = parseInt(mainProductImage.getAttribute('data-current-index') || '0', 10) || 0;
        activateGalleryImageByIndex(currentIndex + delta);
        syncLightboxWithMainImage();
    }

    if (openLightboxBtn) {
        openLightboxBtn.addEventListener('click', openProductLightbox);
    }

    if (mainProductImage && productLightbox) {
        mainProductImage.addEventListener('click', openProductLightbox);
    }

    if (lightboxCloseBtn) {
        lightboxCloseBtn.addEventListener('click', closeProductLightbox);
    }

    if (lightboxBackdrop) {
        lightboxBackdrop.addEventListener('click', closeProductLightbox);
    }

    if (lightboxPrevBtn) {
        lightboxPrevBtn.addEventListener('click', function () {
            stepLightboxImage(-1);
        });
    }

    if (lightboxNextBtn) {
        lightboxNextBtn.addEventListener('click', function () {
            stepLightboxImage(1);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (!productLightbox || productLightbox.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            closeProductLightbox();
        } else if (event.key === 'ArrowLeft') {
            stepLightboxImage(-1);
        } else if (event.key === 'ArrowRight') {
            stepLightboxImage(1);
        }
    });

    // =====================================================================
    // 9. TRANG CHI TIẾT SẢN PHẨM: TĂNG/GIẢM SỐ LƯỢNG & NÚT ĐẶT MUA
    // =====================================================================
    const qtyMinusBtn = document.getElementById('qtyMinusBtn');
    const qtyPlusBtn = document.getElementById('qtyPlusBtn');
    const orderQtyInput = document.getElementById('orderQtyInput');
    const addToCartBtn = document.getElementById('addToCartBtn');
    const buyNowBtn = document.getElementById('buyNowBtn');
    const cartDevNotice = document.getElementById('cartDevNotice');
    const cartDevNoticeText = document.getElementById('cartDevNoticeText');

    function clampOrderQuantity(rawValue) {
        if (!orderQtyInput) {
            return 1;
        }
        const maxStock = parseInt(orderQtyInput.getAttribute('data-max-stock') || orderQtyInput.max || '1', 10);
        if (maxStock <= 0) {
            orderQtyInput.value = '0';
            return 0;
        }

        let parsed = parseInt(String(rawValue), 10);
        if (isNaN(parsed) || parsed < 1) {
            parsed = 1;
        }
        if (parsed > maxStock) {
            parsed = maxStock;
        }
        orderQtyInput.value = String(parsed);
        return parsed;
    }

    if (orderQtyInput && !orderQtyInput.disabled) {
        if (qtyMinusBtn) {
            qtyMinusBtn.addEventListener('click', function () {
                const current = clampOrderQuantity(orderQtyInput.value);
                clampOrderQuantity(current - 1);
            });
        }

        if (qtyPlusBtn) {
            qtyPlusBtn.addEventListener('click', function () {
                const current = clampOrderQuantity(orderQtyInput.value);
                clampOrderQuantity(current + 1);
            });
        }

        orderQtyInput.addEventListener('change', function () {
            clampOrderQuantity(orderQtyInput.value);
        });

        orderQtyInput.addEventListener('input', function () {
            if (orderQtyInput.value !== '') {
                clampOrderQuantity(orderQtyInput.value);
            }
        });
    }

    function getBaseUrl() {
        const meta = document.querySelector('meta[name="base-url"]');
        return meta ? meta.getAttribute('content').replace(/\/+$/, '') : '';
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        const input = document.getElementById('cartGlobalCsrfToken') || document.querySelector('input[name="_csrf_token"]');
        return input ? input.value : '';
    }

    function updateHeaderBadge(count) {
        const safeCount = String(Math.max(0, parseInt(count, 10) || 0));
        document.querySelectorAll('.js-cart-count-badge, #headerCartCountBadge').forEach(function (badge) {
            badge.textContent = safeCount;
        });
    }

    // Đồng bộ thanh mua hàng cố định trên Mobile ở trang Chi tiết sản phẩm (nếu có)
    const mobileStickyAddBtn = document.getElementById('mobileStickyAddCartBtn');
    const mobileStickyBuyBtn = document.getElementById('mobileStickyBuyNowBtn');
    if (mobileStickyAddBtn && addToCartBtn && !addToCartBtn.disabled) {
        mobileStickyAddBtn.addEventListener('click', function () {
            addToCartBtn.click();
        });
    }
    if (mobileStickyBuyBtn && buyNowBtn && !buyNowBtn.disabled) {
        mobileStickyBuyBtn.addEventListener('click', function () {
            buyNowBtn.click();
        });
    }

    if (addToCartBtn && !addToCartBtn.disabled) {
        addToCartBtn.addEventListener('click', function (event) {
            event.preventDefault();
            const productId = addToCartBtn.getAttribute('data-product-id');
            const selectedQty = orderQtyInput ? clampOrderQuantity(orderQtyInput.value) : 1;
            const baseUrl = getBaseUrl();
            const csrfToken = getCsrfToken();

            if (!productId) {
                return;
            }

            addToCartBtn.disabled = true;

            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('quantity', String(selectedQty));
            formData.append('_csrf_token', csrfToken);

            fetch(baseUrl + '/gio-hang/them', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                addToCartBtn.disabled = false;
                if (cartDevNotice && cartDevNoticeText) {
                    if (data.success) {
                        cartDevNoticeText.innerHTML = (data.message || 'Đã thêm sản phẩm vào giỏ hàng thành công!') +
                            ' <a href="' + baseUrl + '/gio-hang" style="color: #0051d5; font-weight: 600; text-decoration: underline; margin-left: 6px;">Xem giỏ hàng ngay &rarr;</a>';
                        cartDevNotice.style.background = '#f0fdf4';
                        cartDevNotice.style.borderColor = '#86efac';
                        cartDevNotice.hidden = false;
                        if (typeof data.cartCount !== 'undefined') {
                            updateHeaderBadge(data.cartCount);
                        }
                    } else {
                        cartDevNoticeText.textContent = data.message || 'Không thể thêm vào giỏ hàng.';
                        cartDevNotice.style.background = '#fef2f2';
                        cartDevNotice.style.borderColor = '#fca5a5';
                        cartDevNotice.hidden = false;
                    }
                }
            })
            .catch(function () {
                addToCartBtn.disabled = false;
                // Fallback: Submit form chuẩn nếu Fetch gặp sự cố mạng
                const form = document.getElementById('productAddToCartForm');
                if (form) {
                    const hiddenQty = document.getElementById('hiddenFormQty');
                    if (hiddenQty) {
                        hiddenQty.value = String(selectedQty);
                    }
                    form.submit();
                }
            });
        });
    }

    if (buyNowBtn && !buyNowBtn.disabled) {
        buyNowBtn.addEventListener('click', function (event) {
            event.preventDefault();
            const selectedQty = orderQtyInput ? clampOrderQuantity(orderQtyInput.value) : 1;
            const form = document.getElementById('productAddToCartForm');
            if (form) {
                const hiddenQty = document.getElementById('hiddenFormQty');
                if (hiddenQty) {
                    hiddenQty.value = String(selectedQty);
                }
                form.action = getBaseUrl() + '/gio-hang/mua-ngay';
                form.submit();
            }
        });
    }

    // =====================================================================
    // 10. TRANG CHI TIẾT SẢN PHẨM: CHUYỂN ĐỔI 4 TAB THÔNG TIN CHI TIẾT
    // =====================================================================
    const detailTabList = document.getElementById('productDetailTabList');
    if (detailTabList) {
        const detailTabs = Array.from(detailTabList.querySelectorAll('[role="tab"]'));
        const detailPanels = Array.from(document.querySelectorAll('.detail-tab-panel[role="tabpanel"]'));

        function activateDetailTab(targetKey, shouldFocusTab) {
            detailTabs.forEach(function (tab) {
                const isTarget = tab.getAttribute('data-tab-target') === targetKey;
                tab.classList.toggle('is-active', isTarget);
                tab.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                tab.setAttribute('tabindex', isTarget ? '0' : '-1');
                if (isTarget && shouldFocusTab) {
                    tab.focus();
                }
            });

            detailPanels.forEach(function (panel) {
                const isMatch = panel.id === 'tab-panel-' + targetKey;
                panel.classList.toggle('is-active', isMatch);
                panel.hidden = !isMatch;
            });
        }

        const linkedPanel = detailPanels.find(function (panel) {
            return '#' + panel.id === window.location.hash;
        });
        if (linkedPanel) {
            activateDetailTab(linkedPanel.dataset.tabPanel, false);
            linkedPanel.scrollIntoView({ block: 'start', behavior: 'instant' });
        }

        detailTabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                const targetKey = tab.getAttribute('data-tab-target') || 'highlights';
                activateDetailTab(targetKey, false);
            });

            tab.addEventListener('keydown', function (event) {
                let nextIndex = null;
                if (event.key === 'ArrowRight') {
                    nextIndex = (index + 1) % detailTabs.length;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = (index - 1 + detailTabs.length) % detailTabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = detailTabs.length - 1;
                }

                if (nextIndex !== null) {
                    event.preventDefault();
                    const nextKey = detailTabs[nextIndex].getAttribute('data-tab-target') || 'highlights';
                    activateDetailTab(nextKey, true);
                }
            });
        });

        // Cho phép liên kết neo nhanh (ví dụ: "Xem biên bản 18 bước ↓") mở trực tiếp Tab Kiểm định
        const openTabAnchors = document.querySelectorAll('[data-open-tab]');
        openTabAnchors.forEach(function (anchor) {
            anchor.addEventListener('click', function (event) {
                const targetTabKey = anchor.getAttribute('data-open-tab');
                if (targetTabKey) {
                    event.preventDefault();
                    activateDetailTab(targetTabKey, false);
                    const targetPanel = document.getElementById('tab-panel-' + targetTabKey);
                    if (targetPanel) {
                        targetPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    }

    // =====================================================================
    // 11. TRANG GIỎ HÀNG (`/gio-hang`): ĐỒNG BỘ THẬT SERVER, TÍNH TIỀN & TƯƠNG TÁC
    // =====================================================================
    const cartItemsList = document.getElementById('cartItemsList');
    if (cartItemsList) {
        const cartSelectAllCheckbox      = document.getElementById('cartSelectAllCheckbox');
        const cartHeaderCountBadge       = document.getElementById('cartHeaderCountBadge');
        const selectedItemsCount         = document.getElementById('selectedItemsCount');
        const calcItemCount              = document.getElementById('calcItemCount');
        const cartSummarySelectedCount   = document.getElementById('cartSummarySelectedCount');
        const cartSubtotalDisplay        = document.getElementById('cartSubtotalDisplay');
        const cartDiscountDisplay        = document.getElementById('cartDiscountDisplay');
        const cartFinalTotalDisplay      = document.getElementById('cartFinalTotalDisplay');
        const cartVoucherInput           = document.getElementById('cartVoucherInput');
        const cartApplyVoucherBtn        = document.getElementById('cartApplyVoucherBtn');
        const voucherChips               = Array.from(document.querySelectorAll('.voucher-chip-tag'));
        const cartProceedCheckoutBtn     = document.getElementById('cartProceedCheckoutBtn');
        const cartClearAllBtn            = document.getElementById('cartClearAllBtn');
        const cartPageActionNotice       = document.getElementById('cartPageActionNotice');
        const cartPageActionNoticeText   = document.getElementById('cartPageActionNoticeText');

        function formatVndCurrency(amount) {
            const safeAmount = Math.max(0, Math.round(amount));
            return safeAmount.toLocaleString('vi-VN') + ' ₫';
        }

        function showCartPageNotice(message, isError) {
            if (!cartPageActionNotice || !cartPageActionNoticeText) {
                return;
            }
            cartPageActionNoticeText.textContent = message;
            cartPageActionNotice.style.background = isError ? '#fef2f2' : '#f0fdf4';
            cartPageActionNotice.style.borderColor = isError ? '#fca5a5' : '#86efac';
            cartPageActionNotice.hidden = false;
        }

        function updateCartDisplays(cartData) {
            if (!cartData) {
                return;
            }

            const totalQty = cartData.total_quantity || 0;
            const subtotal = cartData.subtotal || 0;
            const total    = cartData.total || subtotal;

            if (cartHeaderCountBadge) {
                cartHeaderCountBadge.textContent = '(' + totalQty + ' dàn máy)';
            }
            if (selectedItemsCount) {
                selectedItemsCount.textContent = String(totalQty);
            }
            if (calcItemCount) {
                calcItemCount.textContent = String(totalQty);
            }
            if (cartSummarySelectedCount) {
                cartSummarySelectedCount.textContent = String(totalQty);
            }
            if (cartSubtotalDisplay) {
                cartSubtotalDisplay.textContent = formatVndCurrency(subtotal);
            }
            if (cartFinalTotalDisplay) {
                cartFinalTotalDisplay.textContent = formatVndCurrency(total);
            }
            updateHeaderBadge(totalQty);

            // Cập nhật thành tiền từng sản phẩm
            if (Array.isArray(cartData.items)) {
                cartData.items.forEach(function (it) {
                    const priceEl = document.getElementById('itemSubtotal_' + it.id);
                    if (priceEl) {
                        priceEl.textContent = formatVndCurrency(it.price);
                    }
                    const card = document.getElementById('cartItemCard_' + it.id);
                    if (card) {
                        const input = card.querySelector('.cart-qty-input');
                        if (input) {
                            input.value = String(it.quantity);
                        }
                    }
                });
            }

            // Nếu giỏ hàng trống, render giao diện rỗng
            if (cartData.is_empty || !cartData.items || cartData.items.length === 0) {
                renderEmptyCartState();
            }
        }

        function renderEmptyCartState() {
            const leftCol = document.querySelector('.stitch-cart-left');
            if (leftCol) {
                const selectBar = leftCol.querySelector('.stitch-cart-select-bar');
                if (selectBar) {
                    selectBar.remove();
                }
                const itemsStack = leftCol.querySelector('#cartItemsList');
                if (itemsStack) {
                    itemsStack.innerHTML =
                        '<div class="empty-state" style="background: #fff; border: 1px solid var(--border-light, #e2e8f0); border-radius: 12px; padding: 48px 24px; text-align: center; margin-bottom: 24px;">' +
                            '<div style="font-size: 48px; margin-bottom: 16px;">🛒</div>' +
                            '<h2 class="empty-state-title" style="font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">Giỏ hàng của bạn đang trống</h2>' +
                            '<p style="color: #64748b; font-size: 14px; max-width: 480px; margin: 0 auto 24px;">' +
                                'Bạn chưa có sản phẩm nào trong giỏ hàng. Hãy khám phá ngay các cấu hình PC Gaming, Laptop và Linh kiện cũ nguyên bản đã qua kiểm định 18 bước nghiêm ngặt!' +
                            '</p>' +
                            '<div class="empty-state-actions">' +
                                '<a href="' + getBaseUrl() + '/san-pham" class="btn btn-primary" style="padding: 10px 24px; font-weight: 600;">' +
                                    'Khám phá sản phẩm ngay' +
                                '</a>' +
                            '</div>' +
                        '</div>';
                }
            }

            if (cartProceedCheckoutBtn) {
                cartProceedCheckoutBtn.disabled = true;
                cartProceedCheckoutBtn.classList.add('btn-disabled');
            }
        }

        // Tăng / Giảm số lượng & Xóa sản phẩm qua Fetch / AJAX
        cartItemsList.addEventListener('click', function (event) {
            const minusBtn = event.target.closest('.cart-qty-minus');
            const plusBtn  = event.target.closest('.cart-qty-plus');
            const deleteBtn = event.target.closest('.cart-item-delete-btn');

            if (minusBtn || plusBtn) {
                const btn = minusBtn || plusBtn;
                const productId = btn.getAttribute('data-product-id');
                const card = document.getElementById('cartItemCard_' + productId);
                if (!card) {
                    return;
                }
                const qtyInput = card.querySelector('.cart-qty-input');
                if (!qtyInput) {
                    return;
                }

                const maxStock = Math.max(1, parseInt(qtyInput.getAttribute('max') || '1', 10) || 1);
                const prevQty  = Math.max(1, parseInt(qtyInput.value || '1', 10) || 1);
                let currentQty = prevQty;

                if (minusBtn) {
                    if (prevQty <= 1) {
                        return;
                    }
                    currentQty = prevQty - 1;
                } else if (plusBtn) {
                    if (prevQty >= maxStock) {
                        showCartPageNotice('Số lượng đã đạt giới hạn tồn kho khả dụng (' + maxStock + ').', true);
                        return;
                    }
                    currentQty = Math.min(maxStock, prevQty + 1);
                }

                qtyInput.value = String(currentQty);

                // Gửi request cập nhật lên server
                const formData = new FormData();
                formData.append('product_id', productId);
                formData.append('quantity', String(currentQty));
                formData.append('_csrf_token', getCsrfToken());

                fetch(getBaseUrl() + '/gio-hang/cap-nhat', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.cart) {
                        updateCartDisplays(data.cart);
                        showCartPageNotice(data.message || 'Đã cập nhật số lượng.', false);
                    } else {
                        qtyInput.value = String(prevQty);
                        showCartPageNotice(data.message || 'Không thể cập nhật số lượng.', true);
                    }
                })
                .catch(function () {
                    qtyInput.value = String(prevQty);
                    showCartPageNotice('Lỗi kết nối máy chủ khi cập nhật giỏ hàng.', true);
                });
            } else if (deleteBtn) {
                const productId = deleteBtn.getAttribute('data-product-id');
                if (!productId) {
                    return;
                }

                const card = document.getElementById('cartItemCard_' + productId);
                if (card) {
                    card.style.opacity = '0.5';
                }

                const formData = new FormData();
                formData.append('product_id', productId);
                formData.append('_csrf_token', getCsrfToken());

                fetch(getBaseUrl() + '/gio-hang/xoa', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (card) {
                            card.remove();
                        }
                        if (data.cart) {
                            updateCartDisplays(data.cart);
                        }
                        showCartPageNotice(data.message || 'Đã xóa sản phẩm khỏi giỏ hàng.', false);
                    } else {
                        if (card) {
                            card.style.opacity = '1';
                        }
                        showCartPageNotice(data.message || 'Không thể xóa sản phẩm.', true);
                    }
                })
                .catch(function () {
                    if (card) {
                        card.style.opacity = '1';
                    }
                    showCartPageNotice('Lỗi kết nối máy chủ khi xóa sản phẩm.', true);
                });
            }
        });

        // Xử lý khi người dùng nhập số trực tiếp vào ô số lượng
        cartItemsList.addEventListener('change', function (event) {
            if (event.target.matches('.cart-qty-input')) {
                const input = event.target;
                const productId = input.getAttribute('data-product-id');
                const maxStock = Math.max(1, parseInt(input.getAttribute('max') || '1', 10) || 1);
                let val = parseInt(input.value, 10);

                if (isNaN(val) || val < 1) {
                    val = 1;
                } else if (val > maxStock) {
                    val = maxStock;
                    showCartPageNotice('Số lượng được điều chỉnh về tối đa tồn kho (' + maxStock + ').', true);
                }
                input.value = String(val);

                const formData = new FormData();
                formData.append('product_id', productId);
                formData.append('quantity', String(val));
                formData.append('_csrf_token', getCsrfToken());

                fetch(getBaseUrl() + '/gio-hang/cap-nhat', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.cart) {
                        updateCartDisplays(data.cart);
                        showCartPageNotice(data.message || 'Đã cập nhật số lượng.', false);
                    } else {
                        showCartPageNotice(data.message || 'Không thể cập nhật số lượng.', true);
                    }
                })
                .catch(function () {
                    showCartPageNotice('Lỗi kết nối máy chủ khi cập nhật giỏ hàng.', true);
                });
            }
        });

        // Nút Xóa tất cả
        if (cartClearAllBtn) {
            cartClearAllBtn.addEventListener('click', function (event) {
                event.preventDefault();
                if (!window.confirm('Bạn có chắc chắn muốn xóa toàn bộ sản phẩm khỏi giỏ hàng?')) {
                    return;
                }

                const formData = new FormData();
                formData.append('_csrf_token', getCsrfToken());

                fetch(getBaseUrl() + '/gio-hang/xoa-tat-ca', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        renderEmptyCartState();
                        updateCartDisplays({
                            items: [],
                            subtotal: 0,
                            discount: 0,
                            total: 0,
                            total_quantity: 0,
                            is_empty: true
                        });
                        showCartPageNotice('Đã làm trống giỏ hàng thành công.', false);
                    }
                })
                .catch(function () {
                    // Fallback sang form submit
                    const form = document.getElementById('cartClearAllForm');
                    if (form) {
                        form.submit();
                    }
                });
            });
        }

        // Chọn chip voucher mẫu
        voucherChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                const code = chip.getAttribute('data-code') || '';
                if (cartVoucherInput) {
                    cartVoucherInput.value = code;
                }
                voucherChips.forEach(function (other) {
                    other.classList.toggle('is-active', other === chip);
                });
                showCartPageNotice('Đã chọn mã voucher "' + code + '". Việc áp dụng chiết khấu thực tế từ CSDL sẽ được thực hiện khi tiến hành thanh toán.', false);
            });
        });

        // Bấm nút Áp dụng voucher
        if (cartApplyVoucherBtn) {
            cartApplyVoucherBtn.addEventListener('click', function () {
                const rawCode = cartVoucherInput ? cartVoucherInput.value.trim().toUpperCase() : '';
                if (!rawCode) {
                    showCartPageNotice('Vui lòng nhập mã giảm giá.', true);
                    return;
                }
                showCartPageNotice('Mã voucher "' + rawCode + '" đã được ghi nhận. Hệ thống sẽ kiểm tra điều kiện áp dụng từ CSDL khi chuyển sang bước Thanh toán.', false);
            });
        }

        // Bấm nút Tiến hành thanh toán -> Điều hướng sang trang Checkout (/thanh-toan)
        if (cartProceedCheckoutBtn) {
            cartProceedCheckoutBtn.addEventListener('click', function () {
                if (cartProceedCheckoutBtn.disabled) {
                    return;
                }
                window.location.href = getBaseUrl() + '/thanh-toan';
            });
        }
    }

    // =====================================================================
    // 12. TRANG ĐĂNG NHẬP / ĐĂNG KÝ (`/dang-nhap`): CHUYỂN TAB & ẨN/HIỆN MẬT KHẨU
    // =====================================================================
    const authTabList = document.getElementById('authTabList');
    if (authTabList) {
        const authTabBtns = Array.from(authTabList.querySelectorAll('.auth-tab-btn, .stitch-auth-tab-btn'));
        const loginPanel = document.getElementById('auth-panel-login');
        const registerPanel = document.getElementById('auth-panel-register');
        const authFormNotice = document.getElementById('authFormNotice');

        function activateAuthTab(targetTab) {
            authTabBtns.forEach(function (btn) {
                const isMatch = btn.getAttribute('data-auth-tab') === targetTab;
                btn.classList.toggle('is-active', isMatch);
                btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
            });

            if (loginPanel && registerPanel) {
                const showLogin = targetTab === 'login';
                loginPanel.classList.toggle('is-active', showLogin);
                loginPanel.hidden = !showLogin;
                registerPanel.classList.toggle('is-active', !showLogin);
                registerPanel.hidden = showLogin;
            }

            if (authFormNotice) {
                authFormNotice.hidden = true;
            }
        }

        authTabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetTab = btn.getAttribute('data-auth-tab') || 'login';
                activateAuthTab(targetTab);
            });
        });

        document.querySelectorAll('[data-switch-auth-tab]').forEach(function (switchBtn) {
            switchBtn.addEventListener('click', function () {
                const targetTab = switchBtn.getAttribute('data-switch-auth-tab') || 'login';
                activateAuthTab(targetTab);
            });
        });

        document.querySelectorAll('.toggle-password-btn').forEach(function (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                const targetId = toggleBtn.getAttribute('data-target-input');
                const inputEl = targetId ? document.getElementById(targetId) : null;
                if (!inputEl) {
                    return;
                }
                const isPassword = inputEl.type === 'password';
                inputEl.type = isPassword ? 'text' : 'password';
                toggleBtn.textContent = isPassword ? '🙈' : '👁️';
            });
        });

        // Các biểu mẫu #stitchLoginForm và #stitchRegisterForm được gửi THẬT về máy chủ (POST /dang-nhap, /dang-ky).
        // JavaScript KHÔNG chặn submit (không preventDefault); việc xác thực và kiểm tra dữ liệu do PHP trên server đảm nhiệm.
        // Chỉ ngăn bấm đúp nút gửi để tránh gửi trùng yêu cầu.
        [document.getElementById('stitchLoginForm'), document.getElementById('stitchRegisterForm')].forEach(function (authForm) {
            if (!authForm) {
                return;
            }
            authForm.addEventListener('submit', function () {
                const submitBtn = authForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    window.setTimeout(function () {
                        submitBtn.disabled = true;
                    }, 0);
                }
            });
        });

        const forgotPasswordLink = document.getElementById('forgotPassTrigger') || document.getElementById('forgotPasswordLink');
        if (forgotPasswordLink && authFormNotice) {
            forgotPasswordLink.addEventListener('click', function (event) {
                event.preventDefault();
                authFormNotice.hidden = false;
            });
        }
    }

    // =====================================================================
    // 13. TRANG THANH TOÁN (`/thanh-toan`): HÌNH THỨC NHẬN, VOUCHER SERVER & CHỐNG DOUBLE SUBMIT
    // =====================================================================
    const checkoutForm = document.getElementById('stitchCheckoutForm');
    if (checkoutForm) {
        const deliveryRadios      = Array.from(checkoutForm.querySelectorAll('input[name="delivery_method"]'));
        const paymentRadios       = Array.from(checkoutForm.querySelectorAll('input[name="payment_method"]'));
        const deliveryAddrFields  = document.getElementById('deliveryAddressFields');
        const pickupShowroomBox   = document.getElementById('pickupShowroomFields');
        const shippingCityInput   = document.getElementById('shipping_city');
        const couponInput         = document.getElementById('checkoutCouponInput');
        const applyCouponBtn      = document.getElementById('checkoutApplyCouponBtn');
        const couponMsgEl         = document.getElementById('checkoutCouponMessage');
        const subtotalDisp        = document.getElementById('checkoutSubtotalDisplay');
        const discountDisp        = document.getElementById('checkoutDiscountDisplay');
        const shippingDisp        = document.getElementById('checkoutShippingDisplay');
        const finalTotalDisp      = document.getElementById('checkoutFinalTotalDisplay');
        const expectedSubInput    = document.getElementById('checkoutExpectedSubtotal');
        const expectedFinalInput  = document.getElementById('checkoutExpectedFinalAmount');
        const checkoutSubmitBtn   = document.getElementById('checkoutSubmitBtn');
        const agreePolicyCheckbox = document.getElementById('agreePolicyCheckbox');

        function formatVndClient(amount) {
            return Number(amount || 0).toLocaleString('vi-VN') + ' ₫';
        }

        function refreshCheckoutPreview(showCouponFeedback) {
            const selectedDelivery = checkoutForm.querySelector('input[name="delivery_method"]:checked');
            const deliveryVal      = selectedDelivery ? selectedDelivery.value : 'delivery';

            const formData = new FormData();
            formData.append('_csrf_token', getCsrfToken());
            formData.append('delivery_method', deliveryVal);
            formData.append('shipping_city', shippingCityInput ? shippingCityInput.value.trim() : '');
            formData.append('coupon_code', couponInput ? couponInput.value.trim().toUpperCase() : '');
            const emailEl = document.getElementById('customer_email');
            const phoneEl = document.getElementById('customer_phone');
            formData.append('customer_email', emailEl ? emailEl.value.trim() : '');
            formData.append('customer_phone', phoneEl ? phoneEl.value.trim() : '');

            fetch(getBaseUrl() + '/thanh-toan/xem-truoc', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                if (subtotalDisp) subtotalDisp.textContent = formatVndClient(data.subtotal);
                if (discountDisp) {
                    discountDisp.textContent = data.discount_amount > 0
                        ? ('-' + formatVndClient(data.discount_amount))
                        : '0 ₫';
                }
                if (shippingDisp) {
                    shippingDisp.textContent = data.shipping_fee === 0
                        ? 'Miễn phí (0 ₫)'
                        : formatVndClient(data.shipping_fee);
                }
                if (finalTotalDisp) finalTotalDisp.textContent = formatVndClient(data.final_amount);
                if (expectedSubInput) expectedSubInput.value = String(data.subtotal);
                if (expectedFinalInput) expectedFinalInput.value = String(data.final_amount);

                if (showCouponFeedback && couponMsgEl) {
                    if (data.coupon_message) {
                        couponMsgEl.textContent = data.coupon_message;
                        couponMsgEl.style.color = data.coupon_valid ? '#059669' : '#dc2626';
                        couponMsgEl.hidden = false;
                    } else {
                        couponMsgEl.hidden = true;
                    }
                }
            })
            .catch(function () {});
        }

        deliveryRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                const isPickup = radio.value === 'pickup' && radio.checked;
                if (deliveryAddrFields) deliveryAddrFields.hidden = isPickup;
                if (pickupShowroomBox) pickupShowroomBox.hidden = !isPickup;
                checkoutForm.querySelectorAll('.delivery-tab-option').forEach(function (lbl) {
                    lbl.classList.toggle('is-selected', lbl.querySelector('input:checked') !== null);
                });
                refreshCheckoutPreview(false);
            });
        });

        paymentRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                checkoutForm.querySelectorAll('.payment-method-card').forEach(function (card) {
                    card.classList.toggle('is-selected', card.querySelector('input:checked') !== null);
                });
            });
        });

        if (shippingCityInput) {
            shippingCityInput.addEventListener('change', function () {
                refreshCheckoutPreview(false);
            });
        }

        if (applyCouponBtn) {
            applyCouponBtn.addEventListener('click', function () {
                refreshCheckoutPreview(true);
            });
        }

        // Chống bấm đúp nút xác nhận đặt hàng trên trình duyệt
        let isSubmittingCheckout = false;
        checkoutForm.addEventListener('submit', function (event) {
            if (agreePolicyCheckbox && !agreePolicyCheckbox.checked) {
                event.preventDefault();
                window.alert('Vui lòng đồng ý với chính sách kiểm hàng và bảo hành trước khi đặt hàng.');
                return;
            }
            if (isSubmittingCheckout) {
                event.preventDefault();
                return;
            }
            isSubmittingCheckout = true;
            if (checkoutSubmitBtn) {
                window.setTimeout(function () {
                    checkoutSubmitBtn.disabled = true;
                    checkoutSubmitBtn.textContent = 'Đang xử lý đơn hàng...';
                }, 0);
            }
        });
    }

    // =====================================================================
    // 14. FLOATING CONTACT WIDGET, THÔNG BÁO KÊNH CHƯA CẤU HÌNH & BACK TO TOP
    // =====================================================================
    const fcwRoot = document.getElementById('floatingContactWidget');
    const fcwMobileToggle = document.getElementById('fcwMobileToggleBtn');
    const fcwStatusToast = document.getElementById('fcwStatusToast');
    const backToTopBtn = document.getElementById('backToTopBtn');
    let fcwToastTimer = null;

    function showFcwUnconfiguredToast(channelName) {
        if (!fcwStatusToast) {
            return;
        }
        const prefix = channelName ? (channelName + ': ') : '';
        fcwStatusToast.textContent = prefix + 'Kênh liên hệ đang được cập nhật.';
        fcwStatusToast.hidden = false;
        if (fcwToastTimer) {
            window.clearTimeout(fcwToastTimer);
        }
        fcwToastTimer = window.setTimeout(function () {
            fcwStatusToast.hidden = true;
        }, 3200);
    }

    if (fcwRoot) {
        const unconfiguredButtons = fcwRoot.querySelectorAll('[data-unconfigured-channel]');
        unconfiguredButtons.forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                showFcwUnconfiguredToast(btn.getAttribute('data-unconfigured-channel') || '');
            });
        });
    }

    if (fcwRoot && fcwMobileToggle) {
        function setFcwExpanded(expanded) {
            fcwRoot.setAttribute('data-expanded', expanded ? 'true' : 'false');
            fcwRoot.classList.toggle('is-expanded', expanded);
            fcwMobileToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            fcwMobileToggle.setAttribute('aria-label', expanded ? 'Đóng kênh liên hệ hỗ trợ' : 'Mở kênh liên hệ hỗ trợ');
        }

        fcwMobileToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = fcwRoot.classList.contains('is-expanded');
            setFcwExpanded(!isOpen);
        });

        document.addEventListener('click', function (event) {
            if (fcwRoot.classList.contains('is-expanded') && !fcwRoot.contains(event.target)) {
                setFcwExpanded(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (fcwRoot.classList.contains('is-expanded')) {
                    setFcwExpanded(false);
                }
                if (fcwStatusToast && !fcwStatusToast.hidden) {
                    fcwStatusToast.hidden = true;
                }
            }
        });
    }

    if (backToTopBtn) {
        let scrollTicking = false;
        const SCROLL_THRESHOLD = 320;

        function updateBackToTopVisibility() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
            backToTopBtn.hidden = scrollTop < SCROLL_THRESHOLD;
            scrollTicking = false;
        }

        window.addEventListener('scroll', function () {
            if (!scrollTicking) {
                scrollTicking = true;
                window.requestAnimationFrame(updateBackToTopVisibility);
            }
        }, { passive: true });

        updateBackToTopVisibility();

        backToTopBtn.addEventListener('click', function () {
            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({
                top: 0,
                behavior: reduceMotion ? 'auto' : 'smooth'
            });
        });
    }

    // =====================================================================
    // 15. ACCORDION QUY TRÌNH KIỂM ĐỊNH 18 BƯỚC & FOOTER MOBILE (PROMPT 24)
    // =====================================================================
    function bindAccordionGroup(triggerSelector, containerSelector) {
        const triggers = document.querySelectorAll(triggerSelector);
        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const parent = trigger.closest(containerSelector);
                if (!parent) {
                    return;
                }
                const nextState = !parent.classList.contains('is-open');
                parent.classList.toggle('is-open', nextState);
                trigger.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            });
        });
    }

    bindAccordionGroup('.qa-acc-trigger', '.tlg-qa-item');
    bindAccordionGroup('.footer-acc-trigger', '.footer-acc-col');
});
