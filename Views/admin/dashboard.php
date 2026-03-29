<?php
// Views/admin/dashboard.php
session_start();

// Vérifier si l'utilisateur est connecté et est admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login_admin.php");
    exit();
}

// Connexion à la base de données
require_once '../../config/database.php';
$conn = new mysqli("localhost", "root", "", "stoch_db");

// Récupération des données statistiques
try {
    // Total des produits
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM produits");
    $stmt->execute();
    $totalProduits = $stmt->get_result()->fetch_assoc()['total'];

    // Commandes en attente
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM commandes WHERE statut = 'en_attente'");
    $stmt->execute();
    $commandesAttente = $stmt->get_result()->fetch_assoc()['total'];

    // Articles en rupture de stock
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM produits WHERE quantite <= 5");
    $stmt->execute();
    $ruptureStock = $stmt->get_result()->fetch_assoc()['total'];

    // Produits à réapprovisionner
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM produits WHERE quantite <= 10");
    $stmt->execute();
    $reapprovisionner = $stmt->get_result()->fetch_assoc()['total'];

    // Valeur totale du stock
    $stmt = $conn->prepare("SELECT SUM(quantite * prix_unitaire) as total FROM produits");
    $stmt->execute();
    $valeurStock = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

    // Mouvements récents (dernières 24h)
    $stmt = $conn->prepare("
        SELECT 'entree' as type, COUNT(*) as count 
        FROM mouvements_stock 
        WHERE type_mouvement = 'entree' AND DATE(created_at) = CURDATE()
        UNION ALL
        SELECT 'sortie' as type, COUNT(*) as count 
        FROM mouvements_stock 
        WHERE type_mouvement = 'sortie' AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute();
    $mouvements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Derniers mouvements détaillés
    $stmt = $conn->prepare("
        SELECT m.*, p.nom as produit_nom, u.nom as utilisateur_nom
        FROM mouvements_stock m
        LEFT JOIN produits p ON m.produit_id = p.id_produit
        LEFT JOIN utilisateurs u ON m.utilisateur_id = u.id_utilisateur
        ORDER BY m.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $mouvementsRecents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Alertes stock faible
    $stmt = $conn->prepare("
        SELECT nom, quantite, seuil_alerte 
        FROM produits 
        WHERE quantite <= seuil_alerte 
        ORDER BY quantite ASC 
        LIMIT 5
    ");
    $stmt->execute();
    $alertesStock = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Statistiques mensuelles
    $stmt = $conn->prepare("
        SELECT 
            MONTH(created_at) as mois,
            COUNT(*) as total_mouvements,
            SUM(CASE WHEN type_mouvement = 'entree' THEN 1 ELSE 0 END) as entrees,
            SUM(CASE WHEN type_mouvement = 'sortie' THEN 1 ELSE 0 END) as sorties
        FROM mouvements_stock 
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
        ORDER BY mois
    ");
    $stmt->execute();
    $statsMensuelles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

} catch(Exception $e) {
    // Gestion des erreurs
    $error = "Erreur lors de la récupération des données : " . $e->getMessage();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Dashboard Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <style>
        :root {
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --secondary: #4361ee; /* CORRIGÉ : #3498db → #4361ee */
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
        }

        /* Sidebar amélioré */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        .logo {
            display: flex;
            align-items: center;
            padding: 0 20px 20px;
            border-bottom: 2px solid var(--light);
        }

        .logo i {
            font-size: 2rem;
            color: var(--secondary); /* CORRIGÉ : utilise la nouvelle couleur */
            margin-right: 10px;
        }

        .logo h1 {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .logo span {
            color: var(--secondary); /* CORRIGÉ : utilise la nouvelle couleur */
        }

        .welcome {
            padding: 20px;
            background: var(--light);
            margin: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .welcome h2 {
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 1.1rem;
        }

        .nav-menu {
            list-style: none;
            margin-top: 20px;
            flex: 1;
        }

        .nav-item {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--dark);
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: var(--secondary); /* CORRIGÉ : utilise la nouvelle couleur */
            transition: width 0.3s ease;
        }

        .nav-item.active {
            background: rgba(67, 97, 238, 0.1); /* CORRIGÉ : rgba correspondant à #4361ee */
            color: var(--secondary); /* CORRIGÉ : utilise la nouvelle couleur */
        }

        .nav-item.active::before {
            width: 4px;
        }

        .nav-item:hover {
            background: rgba(67, 97, 238, 0.05); /* CORRIGÉ : rgba correspondant à #4361ee */
            transform: translateX(5px);
        }

        .nav-item i {
            margin-right: 15px;
            font-size: 1.2rem;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .nav-item:hover i {
            transform: scale(1.1);
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 2px solid var(--light);
            padding: 20px;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            font-weight: 600;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
        }

        .logout-btn i {
            margin-right: 8px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: var(--primary);
            margin: 0;
            font-size: 2rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--primary)); /* CORRIGÉ : utilise la nouvelle couleur */
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3); /* CORRIGÉ : rgba correspondant à #4361ee */
        }

        .settings-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .settings-btn:hover {
            background: var(--light);
            transform: rotate(30deg);
        }

        /* Metrics Grid amélioré */
        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary); /* CORRIGÉ : utilise la nouvelle couleur */
        }

        .metric-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .metric-card.warning::before { background: var(--warning); }
        .metric-card.danger::before { background: var(--danger); }
        .metric-card.success::before { background: var(--success); }
        .metric-card.info::before { background: var(--info); }

        .metric-icon {
            width: 70px;
            height: 70px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 1.8rem;
            color: white;
        }

        .metric-content {
            flex: 1;
        }

        .metric-card h3 {
            font-size: 0.9rem;
            color: var(--dark);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .metric-value {
            font-size: 2.2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .metric-description {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-container, .activity-container, .alert-container, .quick-actions-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            transition: transform 0.3s ease;
        }

        .chart-container:hover, .activity-container:hover, .alert-container:hover, .quick-actions-container:hover {
            transform: translateY(-5px);
        }

        .chart-container h2, .activity-container h2, .alert-container h2, .quick-actions-container h2 {
            color: var(--primary);
            margin-bottom: 20px;
            font-size: 1.3rem;
            border-bottom: 2px solid var(--light);
            padding-bottom: 10px;
        }

        /* Alertes */
        .alert-item {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 10px;
            background: #fff3cd;
            border-left: 4px solid var(--warning);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .alert-item.critical {
            background: #f8d7da;
            border-left-color: var(--danger);
        }

        .alert-item:hover {
            transform: translateX(5px);
        }

        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--warning);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .alert-item.critical .alert-icon {
            background: var(--danger);
        }

        /* Quick Actions */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .action-btn {
            background: linear-gradient(135deg, var(--secondary), var(--primary)); /* CORRIGÉ : utilise la nouvelle couleur */
            color: white;
            border: none;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3); /* CORRIGÉ : rgba correspondant à #4361ee */
        }

        .action-btn i {
            font-size: 1.5rem;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .logo h1, .welcome, .nav-item span, .logout-btn span {
                display: none;
            }
            
            .nav-item {
                justify-content: center;
                padding: 15px;
            }
            
            .nav-item i {
                margin-right: 0;
                font-size: 1.3rem;
            }
            
            .logout-btn {
                justify-content: center;
                padding: 15px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .metrics {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-warehouse"></i>
            <h1>GESTION<span>stock</span></h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo $_SESSION['user_nom']; ?></h2>
            <p>Administrateur</p>
        </div>

      <ul class="nav-menu">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de Bord</span>
            </a>
            <a href="categories.php" class="nav-item">
                <i class="fas fa-tags"></i>
                <span>Catégories</span>
            </a>
            <a href="produits.php" class="nav-item active">
                <i class="fas fa-boxes"></i>
                <span>Produits</span>
            </a>
            <a href="mouvements.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Mouvements</span>
            </a>
            <a href="commandes.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i>
                <span>Commandes</span>
            </a>
             <a href="details_commande.php" class="nav-item">
                <i class="fas fa-list-alt"></i>
                <span>Détails Commandes</span>
            </a>
             <a href="fournisseurs.php" class="nav-item">
                <i class="fas fa-users-cog"></i>
                <span>fournisseurs</span>
            </a>
            <a href="rapports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="utilisateurs.php" class="nav-item">
                <i class="fas fa-users-cog"></i>
                <span>Utilisateurs</span>
            </a>
        </ul>
        <div class="sidebar-footer">
            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header fade-in-up">
            <h1><i class="fas fa-tachometer-alt me-2"></i>Tableau de Bord Administrateur</h1>
            <div class="user-info">
                <button class="settings-btn" onclick="openSettings()">
                    <i class="fas fa-cog"></i>
                </button>
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <p style="font-weight: bold;"><?php echo $_SESSION['user_nom']; ?></p>
                    <small>Administrateur</small>
                </div>
            </div>
        </div>

        <!-- Metrics Grid -->
        <div class="metrics">
            <div class="metric-card fade-in-up" style="animation-delay: 0.1s;">
                <div class="metric-icon" style="background: var(--info);">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="metric-content">
                    <h3>Total Produits</h3>
                    <div class="metric-value"><?php echo number_format($totalProduits, 0, ',', ' '); ?></div>
                    <div class="metric-description">Produits en stock</div>
                </div>
            </div>

            <div class="metric-card warning fade-in-up" style="animation-delay: 0.2s;">
                <div class="metric-icon" style="background: var(--warning);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="metric-content">
                    <h3>À Réapprovisionner</h3>
                    <div class="metric-value"><?php echo number_format($reapprovisionner, 0, ',', ' '); ?></div>
                    <div class="metric-description">Stock faible</div>
                </div>
            </div>

            <div class="metric-card danger fade-in-up" style="animation-delay: 0.3s;">
                <div class="metric-icon" style="background: var(--danger);">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="metric-content">
                    <h3>Rupture de Stock</h3>
                    <div class="metric-value"><?php echo number_format($ruptureStock, 0, ',', ' '); ?></div>
                    <div class="metric-description">Produits épuisés</div>
                </div>
            </div>

            <div class="metric-card success fade-in-up" style="animation-delay: 0.4s;">
                <div class="metric-icon" style="background: var(--success);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="metric-content">
                    <h3>Valeur du Stock</h3>
                    <div class="metric-value"><?php echo number_format($valeurStock, 0, ',', ' '); ?> €</div>
                    <div class="metric-description">Valeur totale</div>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Chart Container -->
                <div class="chart-container fade-in-up">
                    <h2><i class="fas fa-chart-bar me-2"></i>Statistiques des Mouvements</h2>
                    <canvas id="movementChart" height="250"></canvas>
                </div>

                <!-- Recent Activity -->
                <div class="activity-container fade-in-up">
                    <h2><i class="fas fa-history me-2"></i>Mouvements Récents</h2>
                    <?php if(!empty($mouvementsRecents)): ?>
                        <?php foreach($mouvementsRecents as $mouvement): ?>
                        <div class="alert-item <?php echo $mouvement['type_mouvement'] === 'entree' ? '' : 'critical'; ?>">
                            <div class="alert-icon">
                                <i class="fas fa-<?php echo $mouvement['type_mouvement'] === 'entree' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                            </div>
                            <div>
                                <strong><?php echo $mouvement['produit_nom']; ?></strong>
                                <div class="metric-description">
                                    <?php echo $mouvement['type_mouvement'] === 'entree' ? 'Entrée' : 'Sortie'; ?> de 
                                    <?php echo $mouvement['quantite']; ?> unités
                                </div>
                                <small><?php echo date('d/m/Y H:i', strtotime($mouvement['created_at'])); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Aucun mouvement récent</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Alertes Stock -->
                <div class="alert-container fade-in-up">
                    <h2><i class="fas fa-bell me-2"></i>Alertes Stock</h2>
                    <?php if(!empty($alertesStock)): ?>
                        <?php foreach($alertesStock as $alerte): ?>
                        <div class="alert-item <?php echo $alerte['quantite'] <= 2 ? 'critical' : ''; ?>">
                            <div class="alert-icon">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <div>
                                <strong><?php echo $alerte['nom']; ?></strong>
                                <div class="metric-description">
                                    Stock: <?php echo $alerte['quantite']; ?> unités
                                </div>
                                <small>Seuil: <?php echo $alerte['seuil_alerte']; ?> unités</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Aucune alerte pour le moment</p>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-container fade-in-up">
                    <h2><i class="fas fa-bolt me-2"></i>Actions Rapides</h2>
                    <div class="action-buttons">
                        <a href="produits.php" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span>Nouveau Produit</span>
                        </a>
                        <a href="mouvements.php?action=entree" class="action-btn">
                            <i class="fas fa-arrow-down"></i>
                            <span>Entrée Stock</span>
                        </a>
                        <a href="rapports.php" class="action-btn">
                            <i class="fas fa-chart-pie"></i>
                            <span>Rapports</span>
                        </a>
                        <a href="commandes.php" class="action-btn">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Commandes</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart.js pour les statistiques
        const ctx = document.getElementById('movementChart').getContext('2d');
        const movementChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                datasets: [
                    {
                        label: 'Entrées',
                        data: [12, 19, 8, 15, 12, 18, 14, 16, 12, 10, 15, 13],
                        backgroundColor: 'rgba(39, 174, 96, 0.8)',
                        borderColor: 'rgba(39, 174, 96, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Sorties',
                        data: [8, 12, 6, 9, 11, 14, 10, 12, 9, 8, 11, 10],
                        backgroundColor: 'rgba(231, 76, 60, 0.8)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Mouvements Mensuels'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.nav-item').forEach(nav => {
                    nav.classList.remove('active');
                });
                this.classList.add('active');
            });
        });

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        function openSettings() {
            window.location.href = 'parametres.php';
        }

        // Animation au scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observer les éléments à animer
        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });
    </script>
</body>
</html>