<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART MONITORING OEE - PT CNC</title>
    <style>
        :root { 
            --bg-color: #000000; 
            --card-bg: #111111; 
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --color-ok: #00ff00; 
            --color-warning: #ffff00; 
            --color-ng: #ff0000; 
            --border-color: #334155;
            --primary: #3b82f6;
        }
        body { background-color: var(--bg-color); color: var(--text-main); font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 20px; overflow-x: hidden; }
        
        .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); width: 280px; height: 100%; background: #1e293b; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); will-change: transform; box-shadow: 4px 0 15px rgba(0,0,0,0.5); display: flex; flex-direction: column; }
        .sidebar.open { transform: translateX(0); }
        #overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999; opacity: 0; visibility: hidden; transition: opacity 0.3s; backdrop-filter: blur(2px); }
        #overlay.show { opacity: 1; visibility: visible; }
        
        .sidebar-header { padding: 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); background: #0f172a;}
        .sidebar-header h2 { margin: 0; font-size: 18px; color: #fff; }
        .close-btn { background: none; border: none; color: var(--text-muted); font-size: 28px; cursor: pointer; transition: color 0.2s; }
        .close-btn:hover { color: #fff; }
        
        .sidebar-menu { display: flex; flex-direction: column; padding-top: 10px; }
        .sidebar-menu a { padding: 15px 25px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: bold; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; transition: all 0.2s;}
        .sidebar-menu a:hover, .sidebar-menu a.active { background: #334155; color: var(--primary); border-left: 4px solid var(--primary); padding-left: 30px;}
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { background: var(--card-bg); border: 1px solid var(--border-color); color: white; border-radius: 8px; width: 40px; height: 40px; font-size: 20px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .menu-btn:hover { background: #334155; }
        .header h1 { margin: 0; font-size: 22px; letter-spacing: 1px; font-weight: 700; background: linear-gradient(90deg, #60a5fa, #a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .filter-container { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: flex-end; flex: 1;}
        .filter-select { background: var(--card-bg); color: white; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; cursor: pointer; outline: none; transition: border-color 0.2s; }
        .filter-select:focus { border-color: var(--primary); }
        
        .checkbox-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; background: var(--card-bg); padding: 5px; border-radius: 8px; border: 1px solid var(--border-color); }
        .checkbox-group label { padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; user-select: none; transition: all 0.2s; }
        .checkbox-group label.active { background: rgba(255,255,255,0.1); }
        .checkbox-group input { display: none; }
        
        .indicator-dot { width: 8px; height: 8px; border-radius: 50%; }
        .bg-run { background: var(--color-ok); box-shadow: 0 0 8px var(--color-ok); }
        .bg-stb { background: var(--color-warning); box-shadow: 0 0 8px var(--color-warning); }
        .bg-alm { background: var(--color-ng); box-shadow: 0 0 8px var(--color-ng); }
        .bg-off { background: #64748b; }
        
        #loading-indicator { text-align: center; color: var(--primary); font-weight: bold; margin: 40px; font-size: 18px; display: flex; justify-content: center; align-items: center; gap: 10px; }
        .spinner { width: 24px; height: 24px; border: 3px solid rgba(59, 130, 246, 0.3); border-radius: 50%; border-top-color: var(--primary); animation: spin 1s ease-in-out infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .slide-page { display: none; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; width: 100%; animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .slide-page.active { display: grid; }
        
        .card { background-color: var(--card-bg); border-radius: 12px; padding: 18px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid var(--border-color); position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .card:hover { transform: translateY(-6px); border-color: var(--primary); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3), 0 0 15px rgba(59, 130, 246, 0.2); }
        .card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; }
        .card.run::before { background: var(--color-ok); }
        .card.stb::before { background: var(--color-warning); }
        .card.alm::before { background: var(--color-ng); }
        .card.off::before { background: #64748b; }
        
        .card-header-title { font-size: 12px; color: #cbd5e1; font-weight: bold; margin-bottom: 6px; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 18px; font-weight: 800; margin-bottom: 4px; color: #ffffff; letter-spacing: -0.5px; }
        .card-id { font-size: 11px; color: var(--primary); font-weight: 600; margin-bottom: 8px; background: rgba(59,130,246,0.1); padding: 2px 8px; border-radius: 10px; display: inline-block; }
        .card-subtitle { font-size: 12px; color: #94a3b8; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .status-badge { padding: 12px 20px; border-radius: 8px; font-size: 16px; font-weight: 900; text-align: center; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 2px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .status-running { background-color: var(--color-ok); color: #000; border: 2px solid var(--color-ok); }
        .status-kuning { background-color: var(--color-warning); color: #000; border: 2px solid var(--color-warning); }
        .status-oren { background-color: #ff9900; color: #000; border: 2px solid #ff9900; }
        .status-merah { background-color: var(--color-ng); color: #fff; border: 2px solid var(--color-ng); }
        .status-off { background-color: #555555; color: #fff; border: 2px solid #555555; }
        
        .donut-container { position: relative; width: 100px; height: 100px; margin: 0 auto 20px auto; }
        .donut { width: 100%; height: 100%; border-radius: 50%; filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); transition: background 1s ease-out; }
        .donut-hole { position: absolute; top: 12%; left: 12%; width: 76%; height: 76%; background-color: var(--card-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column; box-shadow: inset 0 2px 5px rgba(0,0,0,0.5); }
        .donut-val { font-size: 20px; font-weight: 800; color: #fff; }
        .donut-label { font-size: 10px; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; }
        
        .bar-group { text-align: left; margin-bottom: 10px; }
        .bar-label { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 4px; font-weight: 600; color: #cbd5e1; }
        .bar-bg { width: 100%; background-color: #0f172a; border-radius: 6px; height: 8px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.5); }
        .bar-fill { height: 100%; border-radius: 6px; transition: width 1s cubic-bezier(0.4, 0, 0.2, 1); }
        .fill-a { background: linear-gradient(90deg, #2563eb, #60a5fa); box-shadow: 0 0 5px #2563eb; } 
        .fill-p { background: linear-gradient(90deg, #d97706, #fbbf24); box-shadow: 0 0 5px #d97706; } 
        .fill-q { background: linear-gradient(90deg, #7c3aed, #a78bfa); box-shadow: 0 0 5px #7c3aed; }
        
        /* Pagination */
        .pagination-dots { display: flex; justify-content: center; gap: 8px; margin-top: 25px; }
        .page-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--border-color); cursor: pointer; transition: all 0.3s; }
        .page-dot.active { background: var(--primary); transform: scale(1.3); box-shadow: 0 0 8px var(--primary); }
    </style>
</head>
<body>

    <div id="overlay" onclick="toggleSidebar()"></div>
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h2>PT CNC Apps</h2>
            <button class="close-btn" onclick="toggleSidebar()">×</button>
        </div>
        <div class="sidebar-menu">
            <a href="<?= BASE_URL ?>user/index.php" class="active">📊 Dashboard Utama</a>
            <a href="<?= BASE_URL ?>user/history/index.php">📁 History Produksi</a>
            <a href="<?= BASE_URL ?>user/summary_oee.php">📈 Rangkuman OEE</a>
            <?php if(isset($user_role) && $user_role === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/pengaturan_jam.php">⏱️ Master Jam (Template)</a>
                <a href="<?= BASE_URL ?>setting/pengaturan_line.php">⚙️ Pengaturan Line</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'it'): ?>
                <a href="<?= BASE_URL ?>setting/settings_auth.php">🔒 Pengaturan Keamanan</a>
            <?php endif; ?>
            <?php if(isset($user_role) && in_array($user_role, ['admin', 'it'])): ?>
                <a href="<?= BASE_URL ?>admin/skill_matrix.php">🎯 Skill Matrix Mesin</a>
                <a href="<?= BASE_URL ?>admin/data_operator.php">👤 Data Operator</a>
                <a href="<?= BASE_URL ?>admin/master_ct.php">📋 Master Cycle Time (CT)</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>logout.php" style="color: #ef4444; margin-top: 20px;">🚪 Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">☰</button>
            <h1>SMART MONITORING OEE</h1>
            <div id="plant-oee-badge" style="background: rgba(59,130,246,0.15); border: 1px solid var(--primary); padding: 6px 14px; border-radius: 8px; color: #fff; font-weight: bold; margin-left: 10px; font-size: 14px;">
                Plant Average OEE: <span id="plant-oee-val" style="color: #60a5fa; font-size: 18px; margin-left: 5px;">--%</span>
            </div>
        </div>
        <div class="filter-container">
            <button onclick="forceResetShift()" style="background: #ef4444; color: white; border: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 13px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); transition: all 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                🛠️ FORCE RESET SHIFT (TESTING)
            </button>
            <div class="checkbox-group">
                <label id="lbl-RUNNING" class="active"><span class="indicator-dot bg-run"></span><input type="checkbox" value="RUNNING" checked onchange="toggleFilter(this)"> RUNNING</label>
                <label id="lbl-STANDBY" class="active"><span class="indicator-dot bg-stb"></span><input type="checkbox" value="STANDBY" checked onchange="toggleFilter(this)"> STANDBY</label>
                <label id="lbl-ALARM" class="active"><span class="indicator-dot bg-alm"></span><input type="checkbox" value="ALARM" checked onchange="toggleFilter(this)"> ALARM</label>
                <label id="lbl-OFF" class="active"><span class="indicator-dot bg-off"></span><input type="checkbox" value="OFF" checked onchange="toggleFilter(this)"> OFF</label>
                <div style="width: 1px; height: 20px; background: var(--border-color); margin: 0 5px;"></div>
                <label id="lbl-AUTOSLIDE" class="active" style="color:var(--primary);"><input type="checkbox" id="autoSlideToggle" checked onchange="toggleAutoSlide()"> ⏯ AUTO SLIDE</label>
            </div>
            <select id="sortOee" class="filter-select" onchange="renderDashboard()">
                <option value="NONE">Urutkan Normal</option>
                <option value="DESC">OEE Tertinggi</option>
                <option value="ASC">OEE Terendah</option>
            </select>
            <select id="lineFilter" class="filter-select" onchange="renderDashboard()">
                <option value="ALL">Semua Line (Loading...)</option>
            </select>
        </div>
    </div>

    <div id="loading-indicator">
        <div class="spinner"></div> Menghubungkan ke Mesin...
    </div>

    <div id="main-dashboard"></div>
    <div class="pagination-dots" id="pagination"></div>

    <script>
        let allMesinData = [];
        let dynamicLines = [];
        let activeStatusFilters = ['RUNNING', 'STANDBY', 'ALARM', 'OFF'];
        let currentPage = 0;
        let totalPages = 0;
        const itemsPerPage = 10;
        let slideInterval;
        let fetchInterval;

        function toggleSidebar() { 
            document.getElementById("sidebar").classList.toggle("open"); 
            document.getElementById("overlay").classList.toggle("show"); 
        }

        function toggleFilter(checkbox) {
            const val = checkbox.value;
            const lbl = document.getElementById('lbl-' + val);
            if (checkbox.checked) {
                if (!activeStatusFilters.includes(val)) activeStatusFilters.push(val);
                lbl.classList.add('active');
            } else {
                activeStatusFilters = activeStatusFilters.filter(v => v !== val);
                lbl.classList.remove('active');
            }
            renderDashboard();
        }

        function toggleAutoSlide() {
            const toggle = document.getElementById('autoSlideToggle');
            const lbl = document.getElementById('lbl-AUTOSLIDE');
            localStorage.setItem('autoSlideState', toggle.checked);
            if (toggle.checked) {
                lbl.classList.add('active');
                startAutoSlide();
            } else {
                lbl.classList.remove('active');
                stopAutoSlide();
            }
        }

        async function fetchDashboardData() {
            try {
                // Fetch query params to simulate the PHP filters logic to the backend if needed, 
                // but we will do client-side filtering for ultra-fast rendering.
                const response = await fetch('<?= BASE_URL ?>api_dashboard.php');
                const data = await response.json();
                
                if (data.error) {
                    console.error("API Error:", data.error);
                    return;
                }

                allMesinData = data.mesin;
                dynamicLines = data.lines;

                let validMachines = allMesinData.filter(m => m.ppt_seconds > 0);
                if (validMachines.length > 0) {
                    let totalOee = validMachines.reduce((sum, m) => sum + parseFloat(m.calc_oee), 0);
                    let avgOee = (totalOee / validMachines.length).toFixed(1);
                    document.getElementById('plant-oee-val').innerText = avgOee + '%';
                } else {
                    document.getElementById('plant-oee-val').innerText = '0.0%';
                }

                // Update Line Select Options if not matching
                const lineSelect = document.getElementById('lineFilter');
                const currentVal = lineSelect.value;
                if (lineSelect.options.length <= 1 || lineSelect.dataset.loaded !== '1') {
                    let html = `<option value="ALL">Semua Line (${allMesinData.length})</option>`;
                    dynamicLines.forEach(ln => {
                        html += `<option value="${ln}">${ln}</option>`;
                    });
                    lineSelect.innerHTML = html;
                    lineSelect.value = currentVal;
                    lineSelect.dataset.loaded = '1';
                } else {
                    lineSelect.options[0].text = `Semua Line (${allMesinData.length})`;
                }

                document.getElementById('loading-indicator').style.display = 'none';
                renderDashboard(false); // false means don't reset page if we are just polling

            } catch (err) {
                console.error("Fetch failed:", err);
            }
        }

        function renderDashboard(resetPage = true) {
            if (resetPage) currentPage = 0;
            
            const lineFilter = document.getElementById('lineFilter').value;
            const sortOee = document.getElementById('sortOee').value;

            // Apply Filters
            let filtered = allMesinData.filter(row => {
                const matchLine = (lineFilter === 'ALL' || row.line === lineFilter);
                
                let mappedStatus = row.catStatus;
                if (row.catStatus === 'OFF SHIFT' || row.catStatus === 'DOWNTIME') {
                    mappedStatus = 'OFF';
                }
                
                const matchStatus = activeStatusFilters.includes(mappedStatus) || activeStatusFilters.includes(row.catStatus);
                return matchLine && matchStatus;
            });

            // Apply Sort
            if (sortOee === 'DESC') {
                filtered.sort((a, b) => (parseFloat(b.calc_oee) - parseFloat(a.calc_oee)) || a.id_mesin.localeCompare(b.id_mesin));
            } else if (sortOee === 'ASC') {
                filtered.sort((a, b) => (parseFloat(a.calc_oee) - parseFloat(b.calc_oee)) || a.id_mesin.localeCompare(b.id_mesin));
            }

            const newTotalPages = Math.ceil(filtered.length / itemsPerPage);
            if (currentPage >= newTotalPages && newTotalPages > 0) currentPage = 0;

            const container = document.getElementById('main-dashboard');
            const paginator = document.getElementById('pagination');

            if (filtered.length === 0) {
                container.innerHTML = "<div style='text-align:center; padding:50px; color:var(--text-muted); font-size:16px;'><h3>Tidak ada mesin yang sesuai dengan filter.</h3></div>";
                paginator.innerHTML = "";
                totalPages = 0;
                return;
            }

            let needsRebuild = false;
            if (totalPages !== newTotalPages) needsRebuild = true;
            else {
                const existingCards = container.querySelectorAll('.card');
                if (existingCards.length !== filtered.length) needsRebuild = true;
                else {
                    existingCards.forEach((card, index) => {
                        if (card.dataset.mcid !== filtered[index].id_mesin) needsRebuild = true;
                    });
                }
            }

            totalPages = newTotalPages;

            if (needsRebuild) {
                let html = '';
                let dotsHtml = '';

                for (let page = 0; page < totalPages; page++) {
                    const isActive = (page === currentPage) ? 'active' : '';
                    html += `<div class='slide-page ${isActive}' id='page-${page}'>`;
                    
                    const start = page * itemsPerPage;
                    const end = Math.min(start + itemsPerPage, filtered.length);

                    for (let i = start; i < end; i++) {
                        const row = filtered[i];
                        html += `
                            <div class="card off" id="card-${row.id_mesin}" data-mcid="${row.id_mesin}" onclick="window.location.href='detail.php?mcID=${encodeURIComponent(row.id_mesin)}'">
                                <div class="card-header-title">
                                    <span id="loc-${row.id_mesin}"></span>
                                    <span id="shift-${row.id_mesin}" style="font-size:10px; color:#64748b;"></span>
                                </div>
                                <div class="card-title" id="name-${row.id_mesin}"></div>
                                <div class="card-id">ID: ${row.id_mesin}</div>
                                <div class="card-subtitle" id="part-${row.id_mesin}"></div>
                                
                                <div class="status-badge" id="badge-${row.id_mesin}"></div>
                                
                                <div class="donut-container">
                                    <div class="donut" id="donut-${row.id_mesin}"></div>
                                    <div class="donut-hole"><span class="donut-val" id="oee-val-${row.id_mesin}"></span><span class="donut-label">OEE</span></div>
                                </div>
                                
                                <div class="bar-group"><div class="bar-label"><span>Availability</span> <span id="a-val-${row.id_mesin}"></span></div><div class="bar-bg"><div class="bar-fill fill-a" id="a-bar-${row.id_mesin}"></div></div></div>
                                <div class="bar-group"><div class="bar-label"><span>Performance</span> <span id="p-val-${row.id_mesin}"></span></div><div class="bar-bg"><div class="bar-fill fill-p" id="p-bar-${row.id_mesin}"></div></div></div>
                                <div class="bar-group"><div class="bar-label"><span>Quality</span> <span id="q-val-${row.id_mesin}"></span></div><div class="bar-bg"><div class="bar-fill fill-q" id="q-bar-${row.id_mesin}"></div></div></div>
                            </div>`;
                    }
                    html += `</div>`;
                    dotsHtml += `<div class="page-dot ${isActive}" onclick="goToPage(${page})"></div>`;
                }

                container.innerHTML = html;
                paginator.innerHTML = (totalPages > 1) ? dotsHtml : '';
                
                startAutoSlide();
            }

            // SOFT UPDATE VALUES FOR ALL RENDERED CARDS (Prevents flickering and resets of CSS animations)
            filtered.forEach(row => {
                const card = document.getElementById(`card-${row.id_mesin}`);
                if (!card) return;
                
                const oee = parseFloat(row.calc_oee);
                const donutColor = (oee >= 85) ? 'var(--color-ok)' : ((oee >= 75) ? 'var(--color-warning)' : 'var(--color-ng)');
                
                let cardBorderClass = 'off';
                if(row.catStatus === 'RUNNING') cardBorderClass = 'run';
                else if(row.catStatus === 'STANDBY') cardBorderClass = 'stb';
                else if(row.catStatus === 'ALARM') cardBorderClass = 'alm';

                card.className = `card ${cardBorderClass}`;
                document.getElementById(`loc-${row.id_mesin}`).innerText = `📍 ${row.line || 'UNASSIGNED'}`;
                document.getElementById(`shift-${row.id_mesin}`).innerText = row.active_shift || '';
                document.getElementById(`name-${row.id_mesin}`).innerText = row.nama_mesin;
                document.getElementById(`part-${row.id_mesin}`).innerText = `Part: ${row.part_name || 'Belum Set Part'}`;
                
                const badge = document.getElementById(`badge-${row.id_mesin}`);
                badge.className = `status-badge ${row.statusClass}`;
                badge.innerText = row.statusText;
                
                document.getElementById(`donut-${row.id_mesin}`).style.background = `conic-gradient(${donutColor} 0% ${oee}%, var(--border-color) ${oee}% 100%)`;
                document.getElementById(`oee-val-${row.id_mesin}`).innerText = `${oee.toFixed(1)}%`;
                
                const calcA = parseFloat(row.calc_a).toFixed(1);
                document.getElementById(`a-val-${row.id_mesin}`).innerText = `${calcA}%`;
                document.getElementById(`a-bar-${row.id_mesin}`).style.width = `${row.calc_a}%`;
                
                const calcP = parseFloat(row.calc_p).toFixed(1);
                document.getElementById(`p-val-${row.id_mesin}`).innerText = `${calcP}%`;
                document.getElementById(`p-bar-${row.id_mesin}`).style.width = `${row.calc_p}%`;
                
                const calcQ = parseFloat(row.calc_q).toFixed(1);
                document.getElementById(`q-val-${row.id_mesin}`).innerText = `${calcQ}%`;
                document.getElementById(`q-bar-${row.id_mesin}`).style.width = `${row.calc_q}%`;
            });
        }

        function goToPage(page) {
            if(page < 0 || page >= totalPages) return;
            document.querySelectorAll('.slide-page').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.page-dot').forEach(el => el.classList.remove('active'));
            
            document.getElementById('page-' + page).classList.add('active');
            document.querySelectorAll('.page-dot')[page].classList.add('active');
            currentPage = page;
            
            // reset timer if manual click
            stopAutoSlide();
            if(document.getElementById('autoSlideToggle').checked) startAutoSlide();
        }

        function startAutoSlide() {
            stopAutoSlide();
            const toggle = document.getElementById('autoSlideToggle');
            if (toggle && toggle.checked && totalPages > 1) {
                slideInterval = setInterval(() => {
                    const nextPage = (currentPage + 1) % totalPages;
                    goToPage(nextPage);
                }, 10000); // 10 seconds slide
            }
        }

        function stopAutoSlide() {
            if (slideInterval) clearInterval(slideInterval);
        }

        function forceResetShift() {
            if (confirm("Yakin ingin memaksa Tutup Buku sekarang untuk keperluan testing?")) {
                fetch('history/proses_reset.php', { method: 'POST' })
                .then(response => response.text())
                .then(data => {
                    alert("Proses Reset Selesai!\nSistem akan memuat ulang halaman.");
                    window.location.reload();
                })
                .catch(error => {
                    alert("Gagal melakukan reset: " + error);
                });
            }
        }

        // INIT
        window.addEventListener('load', () => {
            const toggle = document.getElementById('autoSlideToggle');
            if (localStorage.getItem('autoSlideState') !== null) { 
                toggle.checked = localStorage.getItem('autoSlideState') === 'true'; 
                toggleAutoSlide(); // apply visual active class
            }
            
            // Fetch initial data immediately
            fetchDashboardData();
            
            // Then poll every 10 seconds for buttery smooth real-time updates without reloading DOM structure entirely
            fetchInterval = setInterval(fetchDashboardData, 10000);
        });
    </script>
</body>
</html>