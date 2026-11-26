/**
 * JavaScript thuần cho Phân hệ Quản trị TL Gaming (`public/assets/js/admin.js`).
 *
 * Chức năng:
 * - Đóng/mở thanh điều hướng Sidebar trên thiết bị di động / màn hình nhỏ.
 * - Tự động chuyển đổi tên danh mục / sản phẩm tiếng Việt có dấu sang Slug chuẩn SEO.
 * - Bật/tắt khung tồn kho số lượng vs Serial trên form sản phẩm.
 * - Thêm/xóa dòng thông số kỹ thuật động (`product_specs`).
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Sidebar Drawer Toggle
    const toggleBtn = document.getElementById('adminMenuToggle');
    const backdrop  = document.getElementById('adminSidebarBackdrop');

    const closeSidebar = () => {
        document.body.classList.remove('admin-sidebar-open');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    };

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const isOpen = document.body.classList.toggle('admin-sidebar-open');
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    const toVietnameseSlug = (str) => {
        return String(str)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/đ/g, 'd')
            .replace(/Đ/g, 'd')
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    };

    const bindAutoSlug = (nameId, slugId) => {
        const nameInput = document.getElementById(nameId);
        const slugInput = document.getElementById(slugId);
        if (!nameInput || !slugInput) {
              return;
        }
        const isCreateMode = slugInput.dataset.autoSlug === '1';
        let userEditedSlug = !isCreateMode && slugInput.value.trim() !== '';

        slugInput.addEventListener('input', () => {
            userEditedSlug = slugInput.value.trim() !== '';
        });

        nameInput.addEventListener('input', () => {
            if (!userEditedSlug) {
                slugInput.value = toVietnameseSlug(nameInput.value);
            }
        });
    };

    // 2. Auto-slug cho Form Danh mục & Form Sản phẩm
    bindAutoSlug('catNameInput', 'catSlugInput');
    bindAutoSlug('prodNameInput', 'prodSlugInput');

    // 3. Chuyển đổi hiển thị khung tồn kho theo has_serials (0: số lượng, 1: serial)
    const hasSerialsSelect = document.getElementById('prodHasSerialsSelect');
    const qtyBox           = document.getElementById('qtyStockBox');
    const serialBox        = document.getElementById('serialStockBox');

    if (hasSerialsSelect && qtyBox && serialBox && hasSerialsSelect.dataset.stockToggle === '1') {
        const syncStockBoxes = () => {
            const isSerial = hasSerialsSelect.value === '1';
            qtyBox.style.display    = isSerial ? 'none' : '';
            serialBox.style.display = isSerial ? '' : 'none';
        };
        hasSerialsSelect.addEventListener('change', syncStockBoxes);
        syncStockBoxes();
    }

    // 4. Quản lý dòng thông số kỹ thuật động (product_specs)
    const specsList  = document.getElementById('specsBuilderList');
    const addSpecBtn = document.getElementById('addSpecRowBtn');

    if (specsList && addSpecBtn) {
        addSpecBtn.addEventListener('click', () => {
            const nextOrder = specsList.querySelectorAll('.adm-spec-row').length + 1;
            const row       = document.createElement('div');
            row.className   = 'adm-spec-row';
            row.innerHTML   = `
                <input type="text" name="spec_group[]" class="adm-input" maxlength="100" placeholder="Nhóm (VD: Cấu hình chính)">
                <input type="text" name="spec_name[]" class="adm-input" maxlength="100" placeholder="Tên thông số (VD: CPU, RAM, VGA)">
                <input type="text" name="spec_value[]" class="adm-input" maxlength="255" placeholder="Giá trị chi tiết">
                <input type="number" name="spec_order[]" class="adm-input adm-spec-order" value="${nextOrder}" title="Thứ tự">
                <button type="button" class="adm-btn adm-btn-danger adm-btn-sm remove-spec-row">Xóa</button>
            `;
            specsList.appendChild(row);
        });

        specsList.addEventListener('click', (e) => {
            const target = e.target;
            if (target instanceof HTMLElement && target.classList.contains('remove-spec-row')) {
                const row = target.closest('.adm-spec-row');
                if (row) {
                    row.remove();
                }
            }
        });
    }

    // 5. Hỗ trợ biểu mẫu kiểm định kỹ thuật 18 bước
    const inspForm       = document.getElementById('inspection18Form');
    const inspStatsBox   = document.getElementById('inspLiveStats');
    const overallSelect  = document.getElementById('overallResultSelect');
    const markAllPassBtn = document.getElementById('btnMarkAllPass');

    if (inspForm && inspStatsBox) {
        const updateInspectionStats = () => {
            const counts = { pass: 0, warning: 0, fail: 0, not_applicable: 0 };
            inspForm.querySelectorAll('.js-step-result:checked').forEach((radio) => {
                if (radio instanceof HTMLInputElement && Object.prototype.hasOwnProperty.call(counts, radio.value)) {
                    counts[radio.value] += 1;
                }
            });

            Object.keys(counts).forEach((key) => {
                const el = inspStatsBox.querySelector(`[data-stat="${key}"]`);
                if (el) {
                    el.textContent = String(counts[key]);
                }
            });

            if (overallSelect instanceof HTMLSelectElement) {
                if (counts.fail > 0) {
                    overallSelect.value = 'failed';
                } else if (counts.warning > 0 && overallSelect.value === 'passed') {
                    overallSelect.value = 'conditional_pass';
                }
            }
        };

        inspForm.addEventListener('change', (e) => {
            const target = e.target;
            if (target instanceof HTMLElement && target.classList.contains('js-step-result')) {
                updateInspectionStats();
            }
        });

        if (markAllPassBtn) {
            markAllPassBtn.addEventListener('click', () => {
                inspForm.querySelectorAll('.js-step-result[value="pass"]').forEach((radio) => {
                    if (radio instanceof HTMLInputElement) {
                        radio.checked = true;
                    }
                });
                updateInspectionStats();
            });
        }

        updateInspectionStats();
    }

    // 6. Biểu đồ doanh thu đơn hàng hoàn tất (Canvas JS thuần, không dùng thư viện ngoài)
    const chartWrap = document.getElementById('revenueChartContainer');
    const chartCanvas = document.getElementById('revenueChartCanvas');
    const chartTooltip = document.getElementById('revenueChartTooltip');

    if (chartWrap && chartCanvas instanceof HTMLCanvasElement) {
        let payload = null;
        try {
            payload = JSON.parse(chartWrap.dataset.revenueSeries || '{}');
        } catch (err) {
            payload = null;
        }

        const points = Array.isArray(payload?.points) ? payload.points : [];
        if (points.length > 0 && payload?.has_data) {
            const fmtVnd = (val) => `${Number(val || 0).toLocaleString('vi-VN')}đ`;
            const fmtShort = (val) => {
                if (val >= 1_000_000_000) return `${(val / 1_000_000_000).toFixed(1)}tỷ`;
                if (val >= 1_000_000) return `${(val / 1_000_000).toFixed(1)}tr`;
                if (val >= 1_000) return `${Math.round(val / 1_000)}k`;
                return String(val);
            };

            let barRects = [];

            const drawChart = () => {
                const dpr = window.devicePixelRatio || 1;
                const width = Math.max(200, Math.floor(chartWrap.clientWidth || 300));
                const height = 260;

                chartCanvas.width = Math.floor(width * dpr);
                chartCanvas.height = Math.floor(height * dpr);
                chartCanvas.style.width = `${width}px`;
                chartCanvas.style.height = `${height}px`;

                const ctx = chartCanvas.getContext('2d');
                if (!ctx) return;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.clearRect(0, 0, width, height);

                const padL = 62;
                const padR = 16;
                const padT = 18;
                const padB = 34;
                const plotW = width - padL - padR;
                const plotH = height - padT - padB;

                const maxRev = Math.max(1, ...points.map((p) => Number(p.revenue || 0)));
                const steps = 4;

                // Lưới ngang & nhãn trục Y
                ctx.font = '11px "Be Vietnam Pro", sans-serif';
                ctx.fillStyle = '#64748b';
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;

                for (let i = 0; i <= steps; i++) {
                    const ratio = i / steps;
                    const y = padT + plotH - ratio * plotH;
                    const tickVal = Math.round(maxRev * ratio);

                    ctx.beginPath();
                    ctx.moveTo(padL, y);
                    ctx.lineTo(width - padR, y);
                    ctx.stroke();

                    ctx.textAlign = 'right';
                    ctx.fillText(fmtShort(tickVal), padL - 8, y + 4);
                }

                // Vẽ cột doanh thu
                const slotW = plotW / points.length;
                const barW = Math.max(4, Math.min(34, slotW * 0.62));
                const labelEvery = Math.max(1, Math.ceil(points.length / 8));
                barRects = [];

                points.forEach((pt, idx) => {
                    const rev = Number(pt.revenue || 0);
                    const barH = rev > 0 ? Math.max(3, (rev / maxRev) * plotH) : 0;
                    const x = padL + idx * slotW + (slotW - barW) / 2;
                    const y = padT + plotH - barH;

                    if (barH > 0) {
                        ctx.fillStyle = '#2563eb';
                        ctx.fillRect(x, y, barW, barH);
                    }

                    barRects.push({
                        x: padL + idx * slotW,
                        w: slotW,
                        pt,
                    });

                    if (idx % labelEvery === 0 || idx === points.length - 1) {
                        ctx.fillStyle = '#475569';
                        ctx.textAlign = 'center';
                        ctx.fillText(String(pt.label || ''), padL + idx * slotW + slotW / 2, height - 10);
                    }
                });
            };

            drawChart();
            window.addEventListener('resize', drawChart);

            if (chartTooltip) {
                chartCanvas.addEventListener('mousemove', (e) => {
                    const rect = chartCanvas.getBoundingClientRect();
                    const mx = e.clientX - rect.left;
                    const hit = barRects.find((r) => mx >= r.x && mx <= r.x + r.w);
                    if (!hit) {
                        chartTooltip.hidden = true;
                        return;
                    }
                    chartTooltip.hidden = false;
                    chartTooltip.innerHTML = `<strong>${hit.pt.label}</strong><br>Doanh thu: ${fmtVnd(hit.pt.revenue)}<br>Đơn hoàn tất: ${hit.pt.orders}`;
                    const leftPos = Math.min(rect.width - 170, Math.max(8, mx + 12));
                    chartTooltip.style.left = `${leftPos}px`;
                    chartTooltip.style.top = '12px';
                });
                chartCanvas.addEventListener('mouseleave', () => {
                    chartTooltip.hidden = true;
                });
            }
        }
    }
});
