<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès strict : Réservé aux Administrateurs (Rôle 1)
check_permission([1]);

$pdo = getPDO();
$adminId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Statistiques globales
$nbUtilisateurs = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$nbComptesTotal = (int) $pdo->query("SELECT COUNT(*) FROM comptes_epargne")->fetchColumn();
$totalEpargneAdmin = (int) $pdo->query("SELECT COALESCE(SUM(solde), 0) FROM comptes_epargne")->fetchColumn();
$nbAuditsSuppressions = (int) $pdo->query("SELECT COUNT(*) FROM audit_suppressions")->fetchColumn();

// Journal d'audit
$stmtAudit = $pdo->query("
    SELECT a.*, u.nom AS admin_nom, u.prenom AS admin_prenom 
    FROM audit_suppressions a 
    LEFT JOIN utilisateurs u ON a.gestionnaire_id = u.id 
    ORDER BY a.date_suppression DESC
");
$journalAudits = $stmtAudit->fetchAll();

// Liste des utilisateurs
$stmtUsers = $pdo->query("
    SELECT u.*, r.nom_role 
    FROM utilisateurs u 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
");
$listeUtilisateurs = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration Centrale | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #070f25;
            --primary-light: #111c38;
            --accent: #f59e0b;
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f4f6f9;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-premium: 0 10px 25px -5px rgba(15, 23, 42, 0.05), 0 8px 10px -6px rgba(15, 23, 42, 0.05);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-body); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }

        /* HEADER EXECUTIF */
        .navbar { 
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); 
            color: white; 
            padding: 16px 36px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3); 
            border-bottom: 2px solid var(--accent); 
            position: sticky; 
            top: 0; 
            z-index: 100; 
        }
        .navbar .brand { font-size: 19px; font-weight: 800; display: flex; align-items: center; gap: 14px; color: #fff; }
        .navbar .brand img { height: 40px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
        .navbar .brand-subtitle { font-size: 10px; color: var(--accent); text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px; display: block; margin-top: 2px; }

        .nav-actions-group { display: flex; align-items: center; gap: 12px; }
        .btn-enterprise { 
            background: rgba(255, 255, 255, 0.08); 
            color: #fff; 
            text-decoration: none; 
            padding: 8px 16px; 
            border-radius: var(--radius-md); 
            font-size: 12.5px; 
            font-weight: 600; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            border: 1px solid rgba(255, 255, 255, 0.12); 
            transition: var(--transition); 
        }
        .btn-enterprise:hover { background: rgba(255, 255, 255, 0.18); transform: translateY(-1px); }

        .user-profile-widget { 
            display: flex; 
            align-items: center; 
            gap: 14px; 
            background: rgba(255, 255, 255, 0.06); 
            padding: 6px 14px; 
            border-radius: 30px; 
            border: 1px solid rgba(255, 255, 255, 0.1); 
        }

        .container { max-width: 1320px; margin: 32px auto; padding: 0 24px; flex: 1; width: 100%; }

        .alert { padding: 16px 20px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 24px; box-shadow: var(--shadow-premium); display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        /* ONGLETS FLUIDES HAUT DE GAMME */
        .main-tabs { display: flex; gap: 12px; margin-bottom: 28px; border-bottom: 2px solid var(--border); padding-bottom: 4px; }
        .tab-item { 
            padding: 12px 22px; 
            font-size: 13.5px; 
            font-weight: 700; 
            color: var(--text-muted); 
            cursor: pointer; 
            border: 1px solid transparent; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            transition: var(--transition); 
            border-radius: var(--radius-md) var(--radius-md) 0 0; 
            background: transparent; 
        }
        .tab-item:hover { color: var(--primary); background: rgba(255,255,255,0.7); }
        .tab-item.active { 
            color: var(--primary); 
            background: #ffffff; 
            border-color: var(--border); 
            border-bottom-color: #ffffff; 
            box-shadow: 0 -4px 15px rgba(0,0,0,0.03); 
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* HERO STATS CARDS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; margin-bottom: 28px; }
        .stat-card { 
            background: var(--card-bg); 
            border-radius: var(--radius-lg); 
            padding: 28px; 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow-premium); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
            position: relative; 
            overflow: hidden; 
            transition: var(--transition); 
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 15px 30px -5px rgba(0,0,0,0.08); }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
        .stat-card.gold::after { background: var(--accent); }
        .stat-card.blue::after { background: #0284c7; }
        .stat-card.green::after { background: var(--success); }
        .stat-card.red::after { background: var(--danger); }

        .stat-card .icon-box { 
            width: 58px; 
            height: 58px; 
            border-radius: 14px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 22px; 
        }
        .stat-card.gold .icon-box { background: #fef3c7; color: var(--accent); }
        .stat-card.blue .icon-box { background: #e0f2fe; color: #0284c7; }
        .stat-card.green .icon-box { background: #d1fae5; color: var(--success); }
        .stat-card.red .icon-box { background: #fee2e2; color: var(--danger); }

        .stat-card .title { font-size: 11.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
        .stat-card .value { font-size: 28px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace; }

        /* BANNIÈRE DE BIENVENUE LUXE */
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary) 0%, #1e293b 100%); 
            color: white; 
            padding: 36px; 
            border-radius: var(--radius-lg); 
            margin-bottom: 28px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: var(--shadow-premium); 
            border-left: 6px solid var(--accent); 
        }
        .welcome-banner h2 { font-size: 24px; font-weight: 800; margin-bottom: 6px; letter-spacing: -0.5px; }
        .welcome-banner p { font-size: 14px; color: #94a3b8; font-weight: 500; }
        .welcome-badge { 
            background: rgba(245, 158, 11, 0.15); 
            color: var(--accent); 
            padding: 8px 16px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 700; 
            border: 1px solid rgba(245, 158, 11, 0.3); 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
        }

        /* CARTES DE CONTENU ET TABLEAUX */
        .card { background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 32px; margin-bottom: 28px; box-shadow: var(--shadow-premium); }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 18px; }

        .search-box { position: relative; max-width: 380px; width: 100%; }
        .search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }
        .search-input { 
            width: 100%; 
            padding: 12px 16px 12px 42px; 
            border: 1.5px solid var(--border); 
            border-radius: var(--radius-md); 
            font-size: 13.5px; 
            outline: none; 
            background: #f8fafc; 
            transition: var(--transition); 
        }
        .search-input:focus { border-color: var(--accent); background: #fff; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12); }

        table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        th { background: #f8fafc; color: var(--primary); padding: 14px 18px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.6px; border-bottom: 2px solid var(--border); }
        td { padding: 16px 18px; border-bottom: 1px solid var(--border); vertical-align: middle; color: #334155; }
        tbody tr { transition: var(--transition); }
        tbody tr:hover { background: #f8fafc; }

        .btn-action { 
            border: none; 
            padding: 9px 16px; 
            border-radius: var(--radius-md); 
            font-size: 12.5px; 
            font-weight: 700; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 7px; 
            color: white; 
            text-decoration: none; 
            transition: var(--transition); 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        }
        .btn-action:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-export { background: var(--success); }
        .btn-danger { background: var(--danger); }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="brand">
            <img src="disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Administration Centrale</span>
            </div>
        </div>

        <div class="nav-actions-group">
            <a href="gestionnaire_dashboard.php" class="btn-enterprise">
                <i class="fa-solid fa-arrow-left-long" style="color: var(--accent);"></i> Portail Gestionnaire
            </a>
        </div>

        <div class="user-profile-widget">
            <div style="text-align: right;">
                <span style="font-size: 13px; font-weight: 700; display: block; color: #fff;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                <small style="font-size: 10.5px; color: var(--accent); font-weight: 600;">Super Administrateur</small>
            </div>
            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-size: 16px; padding-left: 8px;" title="Déconnexion">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <div class="container">

        <?php if ($messageSuccess): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($messageSuccess); ?></div>
        <?php endif; ?>

        <?php if ($messageError): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($messageError); ?></div>
        <?php endif; ?>

        <!-- ONGLETS DE NAVIGATION -->
        <div class="main-tabs">
            <div class="tab-item active" onclick="switchAdminTab('accueil', event)">
                <i class="fa-solid fa-chart-pie"></i> Vue d'ensemble
            </div>
            <div class="tab-item" onclick="switchAdminTab('audit', event)">
                <i class="fa-solid fa-shield-halved"></i> Journal d'Audit & Suppressions
                <span style="background: var(--danger); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($journalAudits); ?></span>
            </div>
            <div class="tab-item" onclick="switchAdminTab('utilisateurs', event)">
                <i class="fa-solid fa-users-gear"></i> Gestion des Utilisateurs
                <span style="background: var(--primary); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($listeUtilisateurs); ?></span>
            </div>
        </div>

        <!-- ONGLET 1 : VUE D'ENSEMBLE -->
        <div id="tab-accueil" class="tab-content active">
            <div class="welcome-banner">
                <div>
                    <h2>Console d'Administration Exécutive</h2>
                    <p>Supervision générale de l'infrastructure, audit des suppressions logiques et gouvernance des rôles.</p>
                </div>
                <div class="welcome-badge">
                    <i class="fa-solid fa-circle-check"></i> Sécurité Maximale
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card gold">
                    <div class="icon-box"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="title">Total Utilisateurs</div>
                        <div class="value" style="color: var(--accent);"><?php echo $nbUtilisateurs; ?></div>
                    </div>
                </div>
                <div class="stat-card blue">
                    <div class="icon-box"><i class="fa-solid fa-vault"></i></div>
                    <div>
                        <div class="title">Comptes Épargne Actifs</div>
                        <div class="value" style="color: #0284c7;"><?php echo $nbComptesTotal; ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="icon-box"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <div class="title">Épargne Globale Système</div>
                        <div class="value" style="color: var(--success);"><?php echo number_format($totalEpargneAdmin, 2, ',', ' '); ?> FCFA</div>
                    </div>
                </div>
                <div class="stat-card red">
                    <div class="icon-box"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="title">Opérations Supprimées (Audit)</div>
                        <div class="value" style="color: var(--danger);"><?php echo $nbAuditsSuppressions; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ONGLET 2 : JOURNAL D'AUDIT & SUPPRESSIONS -->
        <div id="tab-audit" class="tab-content">
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-shield-halved" style="color: var(--danger); margin-right: 8px;"></i> Journal d'Audit - Virements Supprimés</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Traçabilité complète des suppressions d'opérations et motifs associés</small>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button class="btn-action btn-export" onclick="alert('Exportation de l\'audit en cours...')">
                            <i class="fa-solid fa-file-csv"></i> Exporter CSV
                        </button>
                        <button class="btn-action btn-danger" onclick="window.print()">
                            <i class="fa-solid fa-file-pdf"></i> Imprimer / PDF
                        </button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Heure</th>
                                <th>Référence</th>
                                <th>Montant Actualisé</th>
                                <th>Gestionnaire Auteur</th>
                                <th>Motif de Suppression</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($journalAudits)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Aucune suppression enregistrée dans le journal d'audit.</td></tr>
                            <?php else: ?>
                                <?php foreach ($journalAudits as $ja): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($ja['date_suppression'])); ?></td>
                                        <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($ja['reference']); ?></strong></td>
                                        <td style="font-weight: 800; color: var(--danger); font-family: 'Consolas', monospace;"><?php echo number_format($ja['montant'], 2, ',', ' '); ?> FCFA</td>
                                        <td><strong><?php echo htmlspecialchars(($ja['admin_prenom'] ?? '') . ' ' . ($ja['admin_nom'] ?? 'Système')); ?></strong></td>
                                        <td><span style="color: #334155; font-style: italic;"><?php echo htmlspecialchars($ja['motif']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ONGLET 3 : GESTION DES UTILISATEURS -->
        <div id="tab-utilisateurs" class="tab-content">
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 8px;"></i> Gestion des Utilisateurs</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Répertoire centralisé des comptes enregistrés sur la plateforme</small>
                    </div>

                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchUsersInput" class="search-input" placeholder="Filtrer les utilisateurs..." onkeyup="filtrerUtilisateurs()">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="tableUsers">
                        <thead>
                            <tr>
                                <th>Nom & Prénom</th>
                                <th>E-mail</th>
                                <th>Rôle</th>
                                <th>Téléphone</th>
                                <th>Date d'inscription</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listeUtilisateurs)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Aucun utilisateur trouvé.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listeUtilisateurs as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')); ?></strong></td>
                                        <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                        <td>
                                            <span style="background: #e2e8f0; color: #0f172a; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700;">
                                                <?php echo htmlspecialchars($u['nom_role'] ?? 'Utilisateur'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['telephone'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($u['date_creation'] ?? 'now')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        function switchAdminTab(tabId, event) {
            document.querySelectorAll('.tab-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            if (event) event.currentTarget.classList.add('active');
        }

        function filtrerUtilisateurs() {
            const val = document.getElementById('searchUsersInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableUsers tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }
    </script>
</body>
</html>