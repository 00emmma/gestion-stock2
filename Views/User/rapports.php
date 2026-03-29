<?php
// Views/user/rapports.php
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

// Vérifier la structure des tables
$table_columns = [];

// Vérifier les colonnes de la table produits
$result = $conn->query("SHOW COLUMNS FROM produits");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $table_columns['produits'][] = $row['Field'];
    }
}

// Vérifier les colonnes de la table commandes
$result = $conn->query("SHOW COLUMNS FROM commandes");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $table_columns['commandes'][] = $row['Field'];
    }
}

// Déterminer les noms de colonnes réels
$stock_column = 'quantite';
$prix_column = 'prix_unitaire';
$date_column = 'date_creation';
$statut_column = 'statut';
$total_column = 'total';

// Chercher les colonnes existantes
$possible_stock_columns = ['quantite', 'quantite_stock', 'stock', 'quantity', 'qte_stock'];
$possible_prix_columns = ['prix_unitaire', 'prix', 'price', 'prix_vente', 'selling_price'];
$possible_date_columns = ['date_creation', 'date_commande', 'created_at', 'date'];
$possible_statut_columns = ['statut', 'status', 'etat'];
$possible_total_columns = ['total', 'montant', 'amount'];

foreach ($possible_stock_columns as $col) {
    if (in_array($col, $table_columns['produits'])) {
        $stock_column = $col;
        break;
    }
}

foreach ($possible_prix_columns as $col) {
    if (in_array($col, $table_columns['produits'])) {
        $prix_column = $col;
        break;
    }
}

foreach ($possible_date_columns as $col) {
    if (in_array($col, $table_columns['commandes'])) {
        $date_column = $col;
        break;
    }
}

foreach ($possible_statut_columns as $col) {
    if (in_array($col, $table_columns['commandes'])) {
        $statut_column = $col;
        break;
    }
}

foreach ($possible_total_columns as $col) {
    if (in_array($col, $table_columns['commandes'])) {
        $total_column = $col;
        break;
    }
}

// Statistiques des produits
$total_produits = 0;
$produits_faible_stock = 0;
$produits_rupture = 0;
$valeur_stock_total = 0;

if (isset($table_columns['produits'])) {
    // Compter les produits
    $stmt_produits = $conn->prepare("SELECT COUNT(*) as total FROM produits");
    if ($stmt_produits) {
        $stmt_produits->execute();
        $result_produits = $stmt_produits->get_result();
        $total_produits = $result_produits->fetch_assoc()['total'];
    }

    // Compter les produits en faible stock et rupture
    if (in_array($stock_column, $table_columns['produits'])) {
        $stmt_faible = $conn->prepare("SELECT COUNT(*) as total FROM produits WHERE $stock_column < 10 AND $stock_column > 0");
        if ($stmt_faible) {
            $stmt_faible->execute();
            $produits_faible_stock = $stmt_faible->get_result()->fetch_assoc()['total'];
        }

        $stmt_rupture = $conn->prepare("SELECT COUNT(*) as total FROM produits WHERE $stock_column = 0");
        if ($stmt_rupture) {
            $stmt_rupture->execute();
            $produits_rupture = $stmt_rupture->get_result()->fetch_assoc()['total'];
        }
    }

    // Calculer la valeur totale du stock
    if (in_array($prix_column, $table_columns['produits']) && in_array($stock_column, $table_columns['produits'])) {
        $stmt_valeur = $conn->prepare("SELECT SUM($prix_column * $stock_column) as valeur FROM produits");
        if ($stmt_valeur) {
            $stmt_valeur->execute();
            $valeur_stock_total = $stmt_valeur->get_result()->fetch_assoc()['valeur'] ?? 0;
        }
    }
}

// Statistiques des commandes
$total_commandes = 0;
$commandes_mois = 0;
$chiffre_affaire_mois = 0;
$commandes_en_cours = 0;

if (isset($table_columns['commandes'])) {
    // Compter toutes les commandes
    $stmt_total_cmd = $conn->prepare("SELECT COUNT(*) as total FROM commandes");
    if ($stmt_total_cmd) {
        $stmt_total_cmd->execute();
        $total_commandes = $stmt_total_cmd->get_result()->fetch_assoc()['total'];
    }

    // Commandes du mois en cours
    if (in_array($date_column, $table_columns['commandes'])) {
        $stmt_mois = $conn->prepare("SELECT COUNT(*) as total FROM commandes WHERE YEAR($date_column) = YEAR(CURDATE()) AND MONTH($date_column) = MONTH(CURDATE())");
        if ($stmt_mois) {
            $stmt_mois->execute();
            $commandes_mois = $stmt_mois->get_result()->fetch_assoc()['total'];
        }
    }

    // Chiffre d'affaire du mois
    if (in_array($date_column, $table_columns['commandes']) && in_array($total_column, $table_columns['commandes'])) {
        $stmt_ca = $conn->prepare("SELECT SUM($total_column) as total FROM commandes WHERE YEAR($date_column) = YEAR(CURDATE()) AND MONTH($date_column) = MONTH(CURDATE())");
        if ($stmt_ca) {
            $stmt_ca->execute();
            $chiffre_affaire_mois = $stmt_ca->get_result()->fetch_assoc()['total'] ?? 0;
        }
    }

    // Commandes en cours
    if (in_array($statut_column, $table_columns['commandes'])) {
        $stmt_en_cours = $conn->prepare("SELECT COUNT(*) as total FROM commandes WHERE $statut_column = 'en cours' OR $statut_column = 'en_cours' OR $statut_column = 'pending'");
        if ($stmt_en_cours) {
            $stmt_en_cours->execute();
            $commandes_en_cours = $stmt_en_cours->get_result()->fetch_assoc()['total'];
        }
    }
}

// Données pour les graphiques
$ventes_par_mois = [];
$produits_populaires = [];
$statuts_commandes = [];

// Ventes par mois (6 derniers mois)
if (isset($table_columns['commandes']) && in_array($date_column, $table_columns['commandes']) && in_array($total_column, $table_columns['commandes'])) {
    $stmt_ventes_mois = $conn->prepare("
        SELECT 
            YEAR($date_column) as annee,
            MONTH($date_column) as mois,
            COUNT(*) as nb_commandes,
            SUM($total_column) as chiffre_affaire
        FROM commandes 
        WHERE $date_column >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR($date_column), MONTH($date_column)
        ORDER BY annee DESC, mois DESC
        LIMIT 6
    ");
    
    if ($stmt_ventes_mois) {
        $stmt_ventes_mois->execute();
        $result_ventes = $stmt_ventes_mois->get_result();
        while ($row = $result_ventes->fetch_assoc()) {
            $ventes_par_mois[] = $row;
        }
    }
}

// Statuts des commandes
if (isset($table_columns['commandes']) && in_array($statut_column, $table_columns['commandes'])) {
    $stmt_statuts = $conn->prepare("
        SELECT $statut_column, COUNT(*) as nombre
        FROM commandes 
        GROUP BY $statut_column
    ");
    
    if ($stmt_statuts) {
        $stmt_statuts->execute();
        $result_statuts = $stmt_statuts->get_result();
        while ($row = $result_statuts->fetch_assoc()) {
            $statuts_commandes[] = $row;
        }
    }
}

// Produits les plus populaires (basé sur les commandes)
$produits_populaires = [];
if (isset($table_columns['produits']) && in_array($stock_column, $table_columns['produits'])) {
    $stmt_populaires = $conn->prepare("
        SELECT nom_produit, $stock_column as stock, $prix_column as prix
        FROM produits 
        WHERE $stock_column > 0 
        ORDER BY $stock_column DESC 
        LIMIT 5
    ");
    
    if ($stmt_populaires) {
        $stmt_populaires->execute();
        $result_populaires = $stmt_populaires->get_result();
        $produits_populaires = $result_populaires->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports et Statistiques - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(67, 97, 238, 0.4);
        }

        /* Cartes de statistiques améliorées */
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

        /* Section de rapport */
        .report-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .section-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Graphiques */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.3rem;
            color: white;
        }

        .kpi-number {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .kpi-trend {
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .trend-up {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .trend-down {
            background: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        /* Actions de rapport */
        .report-actions {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* Tableaux de données */
        .data-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .table-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px 25px;
        }

        .table-title {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Progress bars */
        .progress-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .progress-item {
            margin-bottom: 20px;
        }

        .progress-item:last-child {
            margin-bottom: 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .progress-bar-custom {
            height: 12px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
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

            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
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
            <h1>STOCKFLOW</h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></h2>
            <p>Utilisateur</p>
        </div>

        <ul class="nav-menu">
            <a href="dashboard.php" class="nav-item">
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
            <a href="rapports.php" class="nav-item active">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="parametres.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
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
        <div class="content-wrapper">
            <div class="header">
                <h1>Rapports et Statistiques</h1>
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

            <!-- En-tête du rapport -->
            <div class="report-actions fade-in-up">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h4 class="mb-2">Rapport de Performance Global</h4>
                        <p class="mb-0 opacity-90">
                            <i class="fas fa-calendar me-2"></i>
                            Données mises à jour le <?php echo date('d/m/Y à H:i'); ?> | 
                            Période d'analyse : 6 derniers mois
                        </p>
                    </div>
                    <div class="col-md-4">
                        <div class="action-buttons">
                            <button class="btn btn-light" onclick="imprimerRapport()">
                                <i class="fas fa-print me-2"></i>Imprimer
                            </button>
                            <button class="btn btn-light" onclick="exporterDonnees()">
                                <i class="fas fa-download me-2"></i>Exporter
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Principaux -->
            <div class="kpi-grid">
                <div class="kpi-card fade-in-up" style="animation-delay: 0.1s; border-top-color: var(--primary);">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="kpi-number text-primary"><?php echo $total_produits; ?></div>
                    <div class="kpi-label">Produits en Stock</div>
                    <div class="kpi-trend trend-up">
                        <i class="fas fa-chart-line me-1"></i>
                        <?php echo $total_produits - $produits_rupture; ?> disponibles
                    </div>
                </div>

                <div class="kpi-card fade-in-up" style="animation-delay: 0.2s; border-top-color: var(--success);">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                        <i class="fas fa-euro-sign"></i>
                    </div>
                    <div class="kpi-number text-success">€<?php echo number_format($chiffre_affaire_mois, 0, ',', ' '); ?></div>
                    <div class="kpi-label">CA du Mois</div>
                    <div class="kpi-trend trend-up">
                        <i class="fas fa-shopping-cart me-1"></i>
                        <?php echo $commandes_mois; ?> commandes
                    </div>
                </div>

                <div class="kpi-card fade-in-up" style="animation-delay: 0.3s; border-top-color: var(--warning);">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, var(--warning), #f7b801);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="kpi-number text-warning"><?php echo $produits_faible_stock; ?></div>
                    <div class="kpi-label">Stocks Faibles</div>
                    <div class="kpi-trend trend-down">
                        <i class="fas fa-times-circle me-1"></i>
                        <?php echo $produits_rupture; ?> ruptures
                    </div>
                </div>

                <div class="kpi-card fade-in-up" style="animation-delay: 0.4s; border-top-color: var(--accent);">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, var(--accent), #f72585);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="kpi-number text-accent"><?php echo $commandes_en_cours; ?></div>
                    <div class="kpi-label">Commandes en Cours</div>
                    <div class="kpi-trend trend-up">
                        <i class="fas fa-list-alt me-1"></i>
                        Total: <?php echo $total_commandes; ?>
                    </div>
                </div>
            </div>

            <!-- Graphiques principaux -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="chart-container fade-in-up" style="animation-delay: 0.5s;">
                        <div class="chart-header">
                            <h3 class="chart-title"><i class="fas fa-chart-line"></i>Évolution des Ventes</h3>
                            <span class="section-badge">6 derniers mois</span>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="ventesChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="chart-container fade-in-up" style="animation-delay: 0.6s;">
                        <div class="chart-header">
                            <h3 class="chart-title"><i class="fas fa-chart-pie"></i>Statuts des Commandes</h3>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="statutsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistiques détaillées -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="progress-container fade-in-up" style="animation-delay: 0.7s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-boxes"></i>État du Stock</h3>
                        </div>
                        
                        <div class="progress-item">
                            <div class="progress-label">
                                <span>Produits disponibles</span>
                                <span><?php echo $total_produits - $produits_rupture - $produits_faible_stock; ?> (<?php echo $total_produits > 0 ? round(($total_produits - $produits_rupture - $produits_faible_stock) / $total_produits * 100) : 0; ?>%)</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-fill" style="width: <?php echo $total_produits > 0 ? (($total_produits - $produits_rupture - $produits_faible_stock) / $total_produits * 100) : 0; ?>%; background: linear-gradient(135deg, var(--success), #4895ef);"></div>
                            </div>
                        </div>

                        <div class="progress-item">
                            <div class="progress-label">
                                <span>Stocks faibles</span>
                                <span><?php echo $produits_faible_stock; ?> (<?php echo $total_produits > 0 ? round($produits_faible_stock / $total_produits * 100) : 0; ?>%)</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-fill" style="width: <?php echo $total_produits > 0 ? ($produits_faible_stock / $total_produits * 100) : 0; ?>%; background: linear-gradient(135deg, var(--warning), #f7b801);"></div>
                            </div>
                        </div>

                        <div class="progress-item">
                            <div class="progress-label">
                                <span>En rupture</span>
                                <span><?php echo $produits_rupture; ?> (<?php echo $total_produits > 0 ? round($produits_rupture / $total_produits * 100) : 0; ?>%)</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-fill" style="width: <?php echo $total_produits > 0 ? ($produits_rupture / $total_produits * 100) : 0; ?>%; background: linear-gradient(135deg, var(--danger), #c1121f);"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="data-table fade-in-up" style="animation-delay: 0.8s;">
                        <div class="table-header">
                            <h3 class="table-title"><i class="fas fa-star"></i>Produits les Plus Populaires</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th>Stock</th>
                                        <th>Prix</th>
                                        <th>Valeur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produits_populaires as $produit): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($produit['nom_produit']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $produit['stock']; ?></span>
                                        </td>
                                        <td class="fw-bold text-primary">
                                            €<?php echo number_format($produit['prix'], 2); ?>
                                        </td>
                                        <td class="fw-bold">
                                            €<?php echo number_format($produit['prix'] * $produit['stock'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Résumé financier -->
            <div class="report-section fade-in-up" style="animation-delay: 0.9s;">
                <div class="section-header">
                    <h3 class="section-title"><i class="fas fa-chart-bar"></i>Résumé Financier</h3>
                </div>
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="h3 text-primary">€<?php echo number_format($valeur_stock_total, 2, ',', ' '); ?></div>
                            <div class="text-muted">Valeur totale du stock</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="h3 text-success">€<?php echo number_format($chiffre_affaire_mois, 2, ',', ' '); ?></div>
                            <div class="text-muted">Chiffre d'affaire mensuel</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="h3 text-info"><?php echo $total_commandes; ?></div>
                            <div class="text-muted">Commandes totales</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Données pour les graphiques
        const moisLabels = [
            <?php 
            $labels = [];
            foreach (array_reverse($ventes_par_mois) as $vente) {
                $mois = $vente['mois'];
                $annee = $vente['annee'];
                $nomMois = [
                    'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin',
                    'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'
                ][$mois - 1];
                $labels[] = "'$nomMois $annee'";
            }
            echo implode(', ', $labels);
            ?>
        ];

        const ventesData = [
            <?php 
            $data = [];
            foreach (array_reverse($ventes_par_mois) as $vente) {
                $data[] = $vente['nb_commandes'];
            }
            echo implode(', ', $data);
            ?>
        ];

        const caData = [
            <?php 
            $data = [];
            foreach (array_reverse($ventes_par_mois) as $vente) {
                $data[] = $vente['chiffre_affaire'] ?? 0;
            }
            echo implode(', ', $data);
            ?>
        ];

        const statutsData = {
            labels: [
                <?php 
                $labels = [];
                foreach ($statuts_commandes as $statut) {
                    $labels[] = "'" . ucfirst($statut[$statut_column]) . "'";
                }
                echo implode(', ', $labels);
                ?>
            ],
            data: [
                <?php 
                $data = [];
                foreach ($statuts_commandes as $statut) {
                    $data[] = $statut['nombre'];
                }
                echo implode(', ', $data);
                ?>
            ]
        };

        // Graphique des ventes
        const ventesCtx = document.getElementById('ventesChart').getContext('2d');
        const ventesChart = new Chart(ventesCtx, {
            type: 'bar',
            data: {
                labels: moisLabels,
                datasets: [
                    {
                        label: 'Nombre de commandes',
                        data: ventesData,
                        backgroundColor: 'rgba(67, 97, 238, 0.8)',
                        borderColor: 'rgba(67, 97, 238, 1)',
                        borderWidth: 2,
                        borderRadius: 8,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Chiffre d\'affaire (€)',
                        data: caData,
                        type: 'line',
                        backgroundColor: 'rgba(247, 37, 133, 0.2)',
                        borderColor: 'rgba(247, 37, 133, 1)',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Nombre de commandes',
                            color: '#4361ee',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(67, 97, 238, 0.1)'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Chiffre d\'affaire (€)',
                            color: '#f72585',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    }
                }
            }
        });

        // Graphique des statuts
        const statutsCtx = document.getElementById('statutsChart').getContext('2d');
        const statutsChart = new Chart(statutsCtx, {
            type: 'doughnut',
            data: {
                labels: statutsData.labels,
                datasets: [{
                    data: statutsData.data,
                    backgroundColor: [
                        'rgba(67, 97, 238, 0.8)',
                        'rgba(247, 184, 1, 0.8)',
                        'rgba(76, 201, 240, 0.8)',
                        'rgba(247, 37, 133, 0.8)',
                        'rgba(114, 9, 183, 0.8)'
                    ],
                    borderWidth: 2,
                    borderColor: 'white',
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11,
                                weight: '600'
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

        // Fonctions utilitaires
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

        function imprimerRapport() {
            window.print();
        }

        function exporterDonnees() {
            Swal.fire({
                title: 'Exporter les données',
                text: 'Choisissez le format d\'export',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Excel',
                cancelButtonText: 'PDF',
                showDenyButton: true,
                denyButtonText: 'CSV',
                background: 'rgba(255, 255, 255, 0.95)'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('Export Excel', 'Le fichier Excel sera généré...', 'success');
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    Swal.fire('Export PDF', 'Le rapport PDF sera généré...', 'success');
                } else if (result.isDenied) {
                    Swal.fire('Export CSV', 'Le fichier CSV sera généré...', 'success');
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

        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.animationPlayState = 'paused';
            observer.observe(el);
        });

        // Actualisation automatique toutes les 5 minutes
        setInterval(() => {
            console.log('Actualisation des données de rapport...');
            // location.reload(); // Décommentez pour actualiser automatiquement
        }, 300000);
    </script>
</body>
</html>
<?php
// Fermer les connexions
if (isset($stmt_produits)) $stmt_produits->close();
if (isset($stmt_faible)) $stmt_faible->close();
if (isset($stmt_rupture)) $stmt_rupture->close();
if (isset($stmt_valeur)) $stmt_valeur->close();
if (isset($stmt_total_cmd)) $stmt_total_cmd->close();
if (isset($stmt_mois)) $stmt_mois->close();
if (isset($stmt_ca)) $stmt_ca->close();
if (isset($stmt_en_cours)) $stmt_en_cours->close();
if (isset($stmt_ventes_mois)) $stmt_ventes_mois->close();
if (isset($stmt_statuts)) $stmt_statuts->close();
if (isset($stmt_populaires)) $stmt_populaires->close();
$conn->close();
?>