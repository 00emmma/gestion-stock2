<?php
// Views/user/dashboard.php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../../index.php');
    exit();
}

$conn = new mysqli("localhost", "root", "", "stoch_db");
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}

// Récupération des données pour les graphiques
try {
    // Statistiques de base
    $total_produits = $conn->query("SELECT COUNT(*) as total FROM produits")->fetch_assoc()['total'];
    $produits_faible_stock = $conn->query("SELECT COUNT(*) as total FROM produits WHERE quantite < 10")->fetch_assoc()['total'];
    $produits_rupture = $conn->query("SELECT COUNT(*) as total FROM produits WHERE quantite = 0")->fetch_assoc()['total'];
    
    // Données pour le graphique des mouvements (exemple)
    $mouvements_data = [
        'labels' => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
        'entrees' => [12, 19, 8, 15, 12, 18, 14],
        'sorties' => [8, 12, 6, 9, 11, 14, 10]
    ];
    
    // Produits les plus populaires
    $produits_populaires = $conn->query("
        SELECT nom_produit, quantite, prix_unitaire 
        FROM produits 
        WHERE quantite > 0 
        ORDER BY quantite DESC 
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Produits en stock faible
    $produits_stock_faible = $conn->query("
        SELECT nom_produit, quantite 
        FROM produits 
        WHERE quantite < 10 AND quantite > 0 
        ORDER BY quantite ASC 
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

} catch(Exception $e) {
    // Données par défaut en cas d'erreur
    $total_produits = 0;
    $produits_faible_stock = 0;
    $produits_rupture = 0;
    $mouvements_data = ['labels' => [], 'entrees' => [], 'sorties' => []];
    $produits_populaires = [];
    $produits_stock_faible = [];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - STOCKFLOW</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --accent: #f72585;
            --success: #4cc9f0;
            --warning: #f7b801;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
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
            backdrop-filter: blur(20px);
            color: var(--dark);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
        }

        .logo {
            display: flex;
            align-items: center;
            padding: 0 20px 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .logo i {
            font-size: 2.2rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: 12px;
        }

        .logo h1 {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome {
            padding: 20px;
            font-size: 14px;
            color: var(--gray);
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .welcome h2 {
            color: var(--primary);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .nav-menu {
            list-style: none;
            margin-top: 20px;
            flex: 1;
        }

        .nav-item {
            padding: 16px 20px;
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
            border-radius: 0 12px 12px 0;
            margin: 4px 0;
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
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transition: width 0.3s ease;
            z-index: -1;
        }

        .nav-item:hover::before,
        .nav-item.active::before {
            width: 100%;
        }

        .nav-item:hover,
        .nav-item.active {
            color: white;
            transform: translateX(8px);
        }

        .nav-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .nav-item:hover i,
        .nav-item.active i {
            transform: scale(1.2);
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 2px solid rgba(67, 97, 238, 0.1);
            padding: 20px;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(230, 57, 70, 0.4);
        }

        .logout-btn i {
            margin-right: 8px;
        }

        /* Contenu principal */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }

        .content-wrapper {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            min-height: calc(100vh - 60px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        /* Header amélioré */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .header h1 {
            color: var(--primary);
            margin: 0;
            font-size: 2.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .user-avatar::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
        }

        .user-avatar:hover::before {
            left: 100%;
        }

        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(67, 97, 238, 0.4);
        }

        /* Cartes de bienvenue améliorées */
        .welcome-section {
            margin-bottom: 40px;
        }

        .welcome-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 25px;
            padding: 40px;
            color: white;
            box-shadow: 0 20px 60px rgba(67, 97, 238, 0.3);
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0% { transform: rotate(45deg) translateX(-100%); }
            100% { transform: rotate(45deg) translateX(100%); }
        }

        .welcome-content h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            line-height: 1.2;
        }

        .welcome-content p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 0;
        }

        .welcome-icon {
            font-size: 4rem;
            opacity: 0.8;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        /* Grille de statistiques améliorée */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .stat-content h3 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 5px;
            line-height: 1;
        }

        .stat-description {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Graphiques améliorés */
        .charts-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        @media (max-width: 1200px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
        }

        .chart-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .chart-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .chart-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Actions rapides améliorées */
        .quick-actions {
            margin-bottom: 40px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: white;
            border-radius: 18px;
            padding: 30px 25px;
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            color: var(--dark);
            text-decoration: none;
        }

        .action-card:hover::before {
            transform: scaleX(1);
        }

        .action-icon {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }

        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .action-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--primary);
        }

        .action-description {
            font-size: 0.85rem;
            color: var(--gray);
            line-height: 1.4;
        }

        /* Tableaux améliorés */
        .data-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        @media (max-width: 992px) {
            .data-section {
                grid-template-columns: 1fr;
            }
        }

        .data-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .data-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .data-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .data-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .data-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .data-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .data-item:hover {
            background: rgba(67, 97, 238, 0.03);
            transform: translateX(5px);
            border-radius: 8px;
            padding: 15px;
        }

        .data-item:last-child {
            border-bottom: none;
        }

        .item-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .item-badge {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }

        .item-text {
            font-weight: 500;
            color: var(--dark);
        }

        .item-value {
            font-weight: 600;
            color: var(--primary);
        }

        /* Badges colorés */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .badge-success {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
        }

        .badge-warning {
            background: linear-gradient(135deg, var(--warning), #f7b801);
            color: white;
        }

        .badge-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .logo h1, .welcome, .nav-item span, .logout-btn span {
                display: none;
            }
            
            .nav-item {
                justify-content: center;
                padding: 20px;
            }
            
            .nav-item i {
                margin-right: 0;
                font-size: 20px;
            }
            
            .main-content {
                margin-left: 80px;
                padding: 15px;
            }

            .content-wrapper {
                padding: 20px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-card {
                padding: 25px;
            }

            .stat-value {
                font-size: 2.2rem;
            }

            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-card {
                padding: 25px 20px;
            }
        }

        @media (max-width: 480px) {
            .actions-grid {
                grid-template-columns: 1fr;
            }

            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .welcome-content h2 {
                font-size: 1.7rem;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-warehouse"></i>
            <h1>STOCK</h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></h2>
            <p>Utilisateur</p>
        </div>

        <ul class="nav-menu">
            <a href="dashboard.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de Bord</span>
            </a>
            <a href="produits.php" class="nav-item">
                <i class="fas fa-boxes"></i>
                <span>Produits</span>
            </a>
            <a href="commandes.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i>
                <span>Commandes</span>
            </a>
            <a href="rapports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="parametres.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>
        </ul>
     <div class="sidebar-footer">
    <button class="logout-btn" onclick="window.location.href='../../logout.php'">
        <i class="fas fa-sign-out-alt"></i>
        <span>Déconnexion</span>
    </button>
</div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>Tableau de Bord Utilisateur</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></p>
                        <small style="color: var(--gray);">Utilisateur</small>
                    </div>
                </div>
            </div>

            <!-- Section de bienvenue -->
            <div class="welcome-section">
                <div class="welcome-card fade-in-up">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="welcome-content">
                                <h2>Bon retour, <?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?> ! 👋</h2>
                                <p>Voici un aperçu complet de votre activité et des performances du stock</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="welcome-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grille de statistiques -->
            <div class="stats-grid">
                <div class="stat-card fade-in-up" style="animation-delay: 0.1s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Produits</h3>
                        <div class="stat-value"><?php echo $total_produits; ?></div>
                        <div class="stat-description">Produits en stock</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.2s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #f7b801);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Stock Faible</h3>
                        <div class="stat-value"><?php echo $produits_faible_stock; ?></div>
                        <div class="stat-description">À réapprovisionner</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.3s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--danger), #c1121f);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>En Rupture</h3>
                        <div class="stat-value"><?php echo $produits_rupture; ?></div>
                        <div class="stat-description">Produits épuisés</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.4s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Mouvements</h3>
                        <div class="stat-value"><?php echo array_sum($mouvements_data['entrees']) + array_sum($mouvements_data['sorties']); ?></div>
                        <div class="stat-description">Cette semaine</div>
                    </div>
                </div>
            </div>

            <!-- Graphiques -->
            <div class="charts-section">
                <div class="chart-container fade-in-up" style="animation-delay: 0.5s;">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-bar"></i>Mouvements Hebdomadaires</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="movementChart"></canvas>
                    </div>
                </div>

                <div class="chart-container fade-in-up" style="animation-delay: 0.6s;">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-pie"></i>Répartition du Stock</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="stockChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="quick-actions">
                <div class="chart-header mb-4">
                    <h3 class="chart-title"><i class="fas fa-bolt"></i>Actions Rapides</h3>
                </div>
                <div class="actions-grid">
                    <a href="produits.php" class="action-card fade-in-up" style="animation-delay: 0.7s;">
                        <div class="action-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="action-title">Gérer Produits</div>
                        <div class="action-description">Consulter et modifier l'inventaire des produits</div>
                    </a>

                    <a href="commandes.php?action=nouveau" class="action-card fade-in-up" style="animation-delay: 0.8s;">
                        <div class="action-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="action-title">Nouvelle Commande</div>
                        <div class="action-description">Créer une nouvelle commande client</div>
                    </a>

                    <a href="rapports.php" class="action-card fade-in-up" style="animation-delay: 0.9s;">
                        <div class="action-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="action-title">Rapports</div>
                        <div class="action-description">Analyser les performances et statistiques</div>
                    </a>

                    <a href="produits.php?filter=stock_faible" class="action-card fade-in-up" style="animation-delay: 1s;">
                        <div class="action-icon" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                        <div class="action-title">Réapprovisionner</div>
                        <div class="action-description">Gérer les stocks faibles et ruptures</div>
                    </a>
                </div>
            </div>

            <!-- Données en temps réel -->
            <div class="data-section">
                <!-- Produits populaires -->
                <div class="data-card fade-in-up" style="animation-delay: 1.1s;">
                    <div class="data-header">
                        <h3 class="data-title"><i class="fas fa-fire"></i>Produits Populaires</h3>
                        <span class="badge badge-success">Top 5</span>
                    </div>
                    <ul class="data-list">
                        <?php foreach ($produits_populaires as $produit): ?>
                        <li class="data-item">
                            <div class="item-content">
                                <div class="item-badge"></div>
                                <span class="item-text"><?php echo htmlspecialchars($produit['nom_produit']); ?></span>
                            </div>
                            <span class="item-value"><?php echo $produit['quantite']; ?> unités</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Alertes stock -->
                <div class="data-card fade-in-up" style="animation-delay: 1.2s;">
                    <div class="data-header">
                        <h3 class="data-title"><i class="fas fa-bell"></i>Alertes Stock</h3>
                        <span class="badge badge-danger"><?php echo count($produits_stock_faible); ?></span>
                    </div>
                    <ul class="data-list">
                        <?php foreach ($produits_stock_faible as $produit): ?>
                        <li class="data-item">
                            <div class="item-content">
                                <div class="item-badge" style="background: var(--danger);"></div>
                                <span class="item-text"><?php echo htmlspecialchars($produit['nom_produit']); ?></span>
                            </div>
                            <span class="item-value" style="color: var(--danger);"><?php echo $produit['quantite']; ?> unités</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Graphique des mouvements
        const movementCtx = document.getElementById('movementChart').getContext('2d');
        const movementChart = new Chart(movementCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($mouvements_data['labels']); ?>,
                datasets: [
                    {
                        label: 'Entrées',
                        data: <?php echo json_encode($mouvements_data['entrees']); ?>,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Sorties',
                        data: <?php echo                        json_encode($mouvements_data['sorties']); ?>,
                        borderColor: '#f72585',
                        backgroundColor: 'rgba(247, 37, 133, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#4361ee',
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#4361ee',
                        bodyColor: '#212529',
                        borderColor: '#4361ee',
                        borderWidth: 1,
                        cornerRadius: 12,
                        displayColors: true,
                        boxPadding: 5
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(67, 97, 238, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#6c757d'
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(67, 97, 238, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#6c757d'
                        },
                        beginAtZero: true
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                animations: {
                    tension: {
                        duration: 1000,
                        easing: 'linear'
                    }
                }
            }
        });

        // Graphique de répartition du stock
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        const stockChart = new Chart(stockCtx, {
            type: 'doughnut',
            data: {
                labels: ['Stock Normal', 'Stock Faible', 'En Rupture'],
                datasets: [{
                    data: [
                        <?php echo $total_produits - $produits_faible_stock - $produits_rupture; ?>,
                        <?php echo $produits_faible_stock; ?>,
                        <?php echo $produits_rupture; ?>
                    ],
                    backgroundColor: [
                        'rgba(67, 97, 238, 0.8)',
                        'rgba(247, 184, 1, 0.8)',
                        'rgba(230, 57, 70, 0.8)'
                    ],
                    borderColor: [
                        '#4361ee',
                        '#f7b801',
                        '#e63946'
                    ],
                    borderWidth: 2,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#4361ee',
                            font: {
                                size: 11,
                                weight: '600'
                            },
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#4361ee',
                        bodyColor: '#212529',
                        borderColor: '#4361ee',
                        borderWidth: 1,
                        cornerRadius: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });

        // Fonction de déconnexion
        function logout() {
            Swal.fire({
                title: 'Déconnexion',
                text: 'Êtes-vous sûr de vouloir vous déconnecter ?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Oui, déconnecter',
                cancelButtonText: 'Annuler',
                background: 'rgba(255, 255, 255, 0.95)',
                backdrop: 'rgba(67, 97, 238, 0.1)'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }

        // Animation au défilement
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observer les éléments avec animation
        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.animationPlayState = 'paused';
            observer.observe(el);
        });

        // Mise à jour en temps réel (simulation)
        function updateRealTimeData() {
            // Simuler des mises à jour de données en temps réel
            const stats = document.querySelectorAll('.stat-value');
            if (stats.length > 0) {
                // Animation de comptage pour les statistiques
                stats.forEach((stat, index) => {
                    const currentValue = parseInt(stat.textContent);
                    if (index === 0 && currentValue < 150) {
                        stat.textContent = currentValue + Math.floor(Math.random() * 3);
                    } else if (index === 1 && currentValue > 0) {
                        const change = Math.random() > 0.7 ? 1 : 0;
                        stat.textContent = Math.max(0, currentValue - change);
                    }
                });
            }
        }

        // Mettre à jour toutes les 30 secondes
        setInterval(updateRealTimeData, 30000);

        // Notification de bienvenue
        document.addEventListener('DOMContentLoaded', function() {
            const welcomeTime = new Date().getHours();
            let greeting = 'Bonne journée';
            
            if (welcomeTime < 12) greeting = 'Bon matin';
            else if (welcomeTime < 18) greeting = 'Bon après-midi';
            else greeting = 'Bonsoir';

            // Afficher une notification toast
            if (typeof Toast !== 'undefined') {
                Toast.fire({
                    icon: 'success',
                    title: `${greeting}, <?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?> !`,
                    background: 'rgba(255, 255, 255, 0.95)',
                    color: '#4361ee'
                });
            }
        });

        // Gestion des erreurs de graphique
        window.addEventListener('error', function(e) {
            console.error('Erreur JavaScript:', e.error);
            const chartContainers = document.querySelectorAll('.chart-wrapper');
            chartContainers.forEach(container => {
                if (!container.querySelector('.error-message')) {
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'error-message text-center p-4';
                    errorMsg.innerHTML = `
                        <i class="fas fa-exclamation-triangle text-warning mb-2" style="font-size: 2rem;"></i>
                        <p class="text-muted">Données temporairement indisponibles</p>
                    `;
                    container.appendChild(errorMsg);
                }
            });
        });

        // Amélioration de l'expérience mobile
        function handleMobileView() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.add('mobile-collapsed');
                mainContent.classList.add('mobile-expanded');
            } else {
                sidebar.classList.remove('mobile-collapsed');
                mainContent.classList.remove('mobile-expanded');
            }
        }

        // Initialiser et écouter les changements de taille
        handleMobileView();
        window.addEventListener('resize', handleMobileView);

        // Ajouter SweetAlert2 pour de meilleures alertes
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        // Initialiser les tooltips Bootstrap si disponible
        if (typeof bootstrap !== 'undefined') {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }

        // Effet de particules pour le fond (optionnel)
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.className = 'particles-container';
            particlesContainer.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: -1;
            `;
            document.body.appendChild(particlesContainer);

            for (let i = 0; i < 20; i++) {
                const particle = document.createElement('div');
                particle.style.cssText = `
                    position: absolute;
                    width: 4px;
                    height: 4px;
                    background: rgba(67, 97, 238, 0.3);
                    border-radius: 50%;
                    animation: float 6s ease-in-out infinite;
                    animation-delay: ${Math.random() * 6}s;
                `;
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;
                particlesContainer.appendChild(particle);
            }
        }

        // Démarrer les particules
        createParticles();
    </script>

    <!-- Inclure SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Inclure Bootstrap JS si nécessaire -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Animation CSS supplémentaire -->
    <style>
        @keyframes float {
            0%, 100% { 
                transform: translateY(0px) translateX(0px) rotate(0deg);
                opacity: 0.3;
            }
            25% { 
                transform: translateY(-20px) translateX(10px) rotate(90deg);
                opacity: 0.6;
            }
            50% { 
                transform: translateY(-10px) translateX(20px) rotate(180deg);
                opacity: 0.8;
            }
            75% { 
                transform: translateY(-30px) translateX(-10px) rotate(270deg);
                opacity: 0.6;
            }
        }

        .particles-container div {
            animation: float 8s ease-in-out infinite;
        }

        /* Améliorations responsive supplémentaires */
        @media (max-width: 480px) {
            .chart-wrapper {
                height: 250px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.8rem;
            }
            
            .welcome-card {
                padding: 25px;
            }
            
            .welcome-content h2 {
                font-size: 1.4rem;
            }
        }

        /* États de chargement */
        .loading {
            position: relative;
            overflow: hidden;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Amélioration de l'accessibilité */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Mode sombre basique */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
            }
            
            .content-wrapper,
            .stat-card,
            .chart-container,
            .data-card,
            .action-card {
                background: rgba(45, 55, 72, 0.95);
                color: #e2e8f0;
                border-color: rgba(255, 255, 255, 0.1);
            }
            
            .stat-content h3,
            .stat-description,
            .action-description {
                color: #cbd5e0;
            }
        }
    </style>
</body>
</html>