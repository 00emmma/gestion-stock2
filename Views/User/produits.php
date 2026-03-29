<?php
// Views/user/produits.php
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

// Vérifier la structure de la table produits
$table_columns = [];
$result = $conn->query("SHOW COLUMNS FROM produits");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $table_columns[] = $row['Field'];
    }
}

// Déterminer les noms de colonnes réels
$nom_column = 'nom_produit'; // Colonne par défaut basée sur votre structure
$description_column = 'description';
$categorie_column = 'categorie';
$prix_column = 'prix_unitaire';
$stock_column = 'quantite';

// Chercher les colonnes existantes
$possible_nom_columns = ['nom_produit', 'nom', 'name', 'libelle', 'designation', 'product_name'];
$possible_desc_columns = ['description', 'desc', 'details'];
$possible_cat_columns = ['categorie', 'category', 'type', 'famille'];
$possible_prix_columns = ['prix_unitaire', 'prix', 'price', 'prix_vente', 'selling_price'];
$possible_stock_columns = ['quantite', 'quantite_stock', 'stock', 'quantity', 'qte_stock'];

foreach ($possible_nom_columns as $col) {
    if (in_array($col, $table_columns)) {
        $nom_column = $col;
        break;
    }
}

foreach ($possible_desc_columns as $col) {
    if (in_array($col, $table_columns)) {
        $description_column = $col;
        break;
    }
}

foreach ($possible_cat_columns as $col) {
    if (in_array($col, $table_columns)) {
        $categorie_column = $col;
        break;
    }
}

foreach ($possible_prix_columns as $col) {
    if (in_array($col, $table_columns)) {
        $prix_column = $col;
        break;
    }
}

foreach ($possible_stock_columns as $col) {
    if (in_array($col, $table_columns)) {
        $stock_column = $col;
        break;
    }
}

// Debug: Afficher les colonnes détectées
error_log("Colonnes détectées - Nom: $nom_column, Description: $description_column, Catégorie: $categorie_column, Prix: $prix_column, Stock: $stock_column");

// Gestion de la recherche et des filtres
$search = $_GET['search'] ?? '';
$categorie_filter = $_GET['categorie'] ?? '';
$stock_filter = $_GET['stock'] ?? '';
$sort_by = $_GET['sort'] ?? $nom_column; // Utiliser la colonne nom détectée par défaut

// Construction de la requête avec gestion des filtres
$where_conditions = [];
$params = [];
$types = '';

// Filtre de recherche
if (!empty($search)) {
    $search_conditions = [];
    if (in_array($nom_column, $table_columns)) {
        $search_conditions[] = "$nom_column LIKE ?";
        $params[] = "%$search%";
        $types .= 's';
    }
    if (in_array($description_column, $table_columns)) {
        $search_conditions[] = "$description_column LIKE ?";
        $params[] = "%$search%";
        $types .= 's';
    }
    
    if (!empty($search_conditions)) {
        $where_conditions[] = "(" . implode(' OR ', $search_conditions) . ")";
    }
}

// Filtre par catégorie
if (!empty($categorie_filter) && in_array($categorie_column, $table_columns)) {
    $where_conditions[] = "$categorie_column = ?";
    $params[] = $categorie_filter;
    $types .= 's';
}

// Filtre par stock
if (!empty($stock_filter) && in_array($stock_column, $table_columns)) {
    if ($stock_filter === 'faible') {
        $where_conditions[] = "$stock_column < 10 AND $stock_column > 0";
    } elseif ($stock_filter === 'rupture') {
        $where_conditions[] = "$stock_column = 0";
    }
}

// Construction de la requête SQL finale
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Gestion du tri - s'assurer que la colonne de tri existe
$allowed_sorts = [$nom_column, $prix_column, $stock_column, $categorie_column];
// Filtrer pour ne garder que les colonnes qui existent réellement
$allowed_sorts = array_filter($allowed_sorts, function($col) use ($table_columns) {
    return in_array($col, $table_columns);
});

// Utiliser la colonne de tri si elle est autorisée, sinon utiliser la colonne nom
$order_column = in_array($sort_by, $allowed_sorts) ? $sort_by : $nom_column;
$order_direction = 'ASC';

// Construction de la requête finale
$query = "SELECT * FROM produits $where_sql ORDER BY $order_column $order_direction";

// Debug
error_log("Requête SQL: $query");
error_log("Paramètres: " . implode(', ', $params));

try {
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Erreur de préparation de la requête: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result_produits = $stmt->get_result();
    $produits = $result_produits->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    error_log("Erreur lors de l'exécution de la requête: " . $e->getMessage());
    // Requête de fallback sans ORDER BY problématique
    $query_fallback = "SELECT * FROM produits $where_sql";
    $stmt = $conn->prepare($query_fallback);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result_produits = $stmt->get_result();
    $produits = $result_produits->fetch_all(MYSQLI_ASSOC);
}

// Récupérer les catégories distinctes pour le filtre
$categories = [];
if (in_array($categorie_column, $table_columns)) {
    $stmt_categories = $conn->query("SELECT DISTINCT $categorie_column as categorie FROM produits WHERE $categorie_column IS NOT NULL AND $categorie_column != '' ORDER BY $categorie_column");
    if ($stmt_categories) {
        $categories = $stmt_categories->fetch_all(MYSQLI_ASSOC);
    }
}

// Compter les produits par statut de stock
$total_produits = count($produits);
$produits_faible_stock = 0;
$produits_rupture = 0;
$produits_disponibles = 0;

foreach ($produits as $produit) {
    $stock = $produit[$stock_column] ?? 0;
    if ($stock == 0) {
        $produits_rupture++;
    } elseif ($stock < 10) {
        $produits_faible_stock++;
    } else {
        $produits_disponibles++;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Produits - STOCKFLOW</title>
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

        /* Barre de recherche améliorée */
        .search-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .search-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .search-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        /* Tableau amélioré */
        .table-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .table-title {
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .table {
            margin: 0;
        }

        .table th {
            background: rgba(67, 97, 238, 0.05);
            color: var(--primary);
            font-weight: 700;
            padding: 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 20px;
            vertical-align: middle;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(67, 97, 238, 0.03);
            transform: translateX(5px);
        }

        /* Badges améliorés */
        .badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
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

        .badge-secondary {
            background: linear-gradient(135deg, var(--gray), #adb5bd);
            color: white;
        }

        /* Progress bar améliorée */
        .progress {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-bar {
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        /* Boutons d'action */
        .btn-action {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.3s ease;
            margin: 2px;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* État vide */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--dark);
            margin-bottom: 10px;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .search-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .table-header {
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
            <a href="produits.php" class="nav-item active">
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
                <h1>Gestion des Produits</h1>
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

            <!-- Statistiques -->
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
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Disponibles</h3>
                        <div class="stat-value"><?php echo $produits_disponibles; ?></div>
                        <div class="stat-description">En stock normal</div>
                    </div>
                </div>
            </div>

            <!-- Barre de recherche et filtres -->
            <div class="search-section fade-in-up" style="animation-delay: 0.5s;">
                <div class="search-header">
                    <h3 class="search-title"><i class="fas fa-search"></i>Recherche et Filtres</h3>
                </div>
                
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Rechercher</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Nom ou description...">
                                <span class="input-group-text bg-primary text-white">
                                    <i class="fas fa-search"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($categories)): ?>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Catégorie</label>
                            <select class="form-select" name="categorie">
                                <option value="">Toutes les catégories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['categorie']); ?>" 
                                        <?php echo $categorie_filter === $cat['categorie'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['categorie']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">État du stock</label>
                            <select class="form-select" name="stock">
                                <option value="">Tous les stocks</option>
                                <option value="faible" <?php echo $stock_filter === 'faible' ? 'selected' : ''; ?>>Stock faible</option>
                                <option value="rupture" <?php echo $stock_filter === 'rupture' ? 'selected' : ''; ?>>En rupture</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="form-group">
                            <label class="form-label">Trier par</label>
                            <select class="form-select" name="sort">
                                <option value="<?php echo $nom_column; ?>" <?php echo $sort_by === $nom_column ? 'selected' : ''; ?>>Nom</option>
                                <?php if (in_array($prix_column, $table_columns)): ?>
                                <option value="<?php echo $prix_column; ?>" <?php echo $sort_by === $prix_column ? 'selected' : ''; ?>>Prix</option>
                                <?php endif; ?>
                                <?php if (in_array($stock_column, $table_columns)): ?>
                                <option value="<?php echo $stock_column; ?>" <?php echo $sort_by === $stock_column ? 'selected' : ''; ?>>Stock</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="search-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Appliquer les filtres
                            </button>
                            <?php if (!empty($search) || !empty($categorie_filter) || !empty($stock_filter)): ?>
                                <a href="produits.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Effacer les filtres
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Liste des produits -->
            <div class="table-container fade-in-up" style="animation-delay: 0.6s;">
                <div class="table-header">
                    <h3 class="table-title"><i class="fas fa-list"></i>Liste des Produits</h3>
                    <div class="d-flex align-items-center gap-3">
                        <?php if (!empty($search) || !empty($categorie_filter) || !empty($stock_filter)): ?>
                            <span class="table-badge">Filtré</span>
                        <?php endif; ?>
                        <span class="table-badge"><?php echo count($produits); ?> produit(s)</span>
                    </div>
                </div>

                <?php if (empty($produits)): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h4>Aucun produit trouvé</h4>
                        <p>
                            <?php if (!empty($search) || !empty($categorie_filter) || !empty($stock_filter)): ?>
                                Aucun produit ne correspond à vos critères de recherche.
                            <?php else: ?>
                                Aucun produit n'est disponible pour le moment.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search) || !empty($categorie_filter) || !empty($stock_filter)): ?>
                            <a href="produits.php" class="btn btn-primary mt-3">
                                <i class="fas fa-redo"></i> Réinitialiser la recherche
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Produit</th>
                                    <?php if (in_array($categorie_column, $table_columns)): ?>
                                    <th>Catégorie</th>
                                    <?php endif; ?>
                                    <?php if (in_array($prix_column, $table_columns)): ?>
                                    <th>Prix</th>
                                    <?php endif; ?>
                                    <?php if (in_array($stock_column, $table_columns)): ?>
                                    <th>Stock</th>
                                    <th>Statut</th>
                                    <?php endif; ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produits as $produit): ?>
                                    <?php
                                    $nom_produit = $produit[$nom_column] ?? 'Produit #' . $produit['id_produit'];
                                    $description_produit = $produit[$description_column] ?? '';
                                    $categorie_produit = $produit[$categorie_column] ?? '';
                                    $prix_produit = $produit[$prix_column] ?? 0;
                                    $stock_produit = $produit[$stock_column] ?? 0;
                                    
                                    // Déterminer le badge de statut
                                    if ($stock_produit == 0) {
                                        $badge_class = 'badge-danger';
                                        $statut_text = 'Rupture';
                                        $progress_class = 'bg-danger';
                                    } elseif ($stock_produit < 10) {
                                        $badge_class = 'badge-warning';
                                        $statut_text = 'Stock faible';
                                        $progress_class = 'bg-warning';
                                    } else {
                                        $badge_class = 'badge-success';
                                        $statut_text = 'Disponible';
                                        $progress_class = 'bg-success';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded p-2 me-3">
                                                    <i class="fas fa-box text-primary"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($nom_produit); ?></div>
                                                    <?php if (!empty($description_produit)): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars(substr($description_produit, 0, 50)); ?>...</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <?php if (in_array($categorie_column, $table_columns)): ?>
                                        <td>
                                            <?php if (!empty($categorie_produit)): ?>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars($categorie_produit); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <?php if (in_array($prix_column, $table_columns)): ?>
                                        <td class="fw-bold text-primary">
                                            €<?php echo number_format($prix_produit, 2); ?>
                                        </td>
                                        <?php endif; ?>
                                        <?php if (in_array($stock_column, $table_columns)): ?>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="progress" style="height: 8px; width: 100px;">
                                                    <?php
                                                    $max_stock = max(100, $stock_produit);
                                                    $percentage = ($stock_produit / $max_stock) * 100;
                                                    ?>
                                                    <div class="progress-bar <?php echo $progress_class; ?>" 
                                                         style="width: <?php echo $percentage; ?>%">
                                                    </div>
                                                </div>
                                                <span class="fw-bold"><?php echo $stock_produit; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $statut_text; ?>
                                            </span>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirDetails(<?php echo $produit['id_produit']; ?>)"
                                                        title="Voir les détails">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-success"
                                                        onclick="ajouterAuPanier(<?php echo $produit['id_produit']; ?>)"
                                                        title="Ajouter au panier"
                                                        <?php echo $stock_produit == 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-cart-plus"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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

        // Fonctions pour les produits
        function voirDetails(produitId) {
            Swal.fire({
                title: 'Détails du produit',
                text: 'Affichage des détails du produit ID: ' + produitId,
                icon: 'info',
                confirmButtonText: 'Fermer',
                background: 'rgba(255, 255, 255, 0.95)'
            });
        }

        function ajouterAuPanier(produitId) {
            Swal.fire({
                title: 'Produit ajouté !',
                text: 'Le produit a été ajouté au panier avec succès.',
                icon: 'success',
                confirmButtonText: 'OK',
                background: 'rgba(255, 255, 255, 0.95)'
            });
        }

        // Auto-submit des filtres
        document.addEventListener('DOMContentLoaded', function() {
            const selects = document.querySelectorAll('select[name="categorie"], select[name="stock"], select[name="sort"]');
            selects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

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
        });
    </script>
</body>
</html>
<?php
// Fermer les connexions
if (isset($stmt)) $stmt->close();
if (isset($stmt_categories)) $stmt_categories->close();
$conn->close();
?>