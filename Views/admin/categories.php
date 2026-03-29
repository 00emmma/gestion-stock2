<?php
// Views/admin/categories.php
session_start();

// Vérifier si l'utilisateur est connecté et est admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login_admin.php');
    exit();
}

// Connexion à la base de données
$conn = new mysqli("localhost", "root", "", "stoch_db");
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}

// Variables pour la recherche et le filtrage
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$sort_by = $_GET['sort'] ?? 'nom_categorie';
$sort_order = $_GET['order'] ?? 'asc';
$page = $_GET['page'] ?? 1;
$limit = 10; // Nombre d'éléments par page
$offset = ($page - 1) * $limit;

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(nom_categorie LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if (!empty($statut_filter)) {
    $where_conditions[] = "statut = ?";
    $params[] = $statut_filter;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Ordre de tri sécurisé
$allowed_sorts = ['nom_categorie', 'date_creation', 'date_modification', 'statut'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'nom_categorie';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Compter le nombre total de catégories pour la pagination
$count_sql = "SELECT COUNT(*) as total FROM categories $where_sql";
if (!empty($params)) {
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_categories = $result_count->fetch_assoc()['total'];
} else {
    $result_count = $conn->query($count_sql);
    $total_categories = $result_count->fetch_assoc()['total'];
}

$total_pages = ceil($total_categories / $limit);

// Récupération des catégories avec filtres et pagination
$categories = [];
$sql = "SELECT * FROM categories $where_sql ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$categories = $result->fetch_all(MYSQLI_ASSOC);

// Compter le nombre de produits par catégorie
$produits_par_categorie = [];
$stmt_count = $conn->query("
    SELECT categorie_id, COUNT(*) as nb_produits 
    FROM produits 
    WHERE categorie_id IS NOT NULL 
    GROUP BY categorie_id
");
if ($stmt_count) {
    $counts = $stmt_count->fetch_all(MYSQLI_ASSOC);
    foreach ($counts as $count) {
        $produits_par_categorie[$count['categorie_id']] = $count['nb_produits'];
    }
}

// Gestion de l'ajout de catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_categorie'])) {
    $nom_categorie = trim($_POST['nom_categorie']);
    $description = trim($_POST['description']);
    $couleur = $_POST['couleur'] ?? '#3498db';

    // Vérifier si la catégorie existe déjà
    $stmt_check = $conn->prepare("SELECT id_categorie FROM categories WHERE nom_categorie = ?");
    $stmt_check->bind_param("s", $nom_categorie);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $error = "Une catégorie avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("INSERT INTO categories (nom_categorie, description, couleur) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $nom_categorie, $description, $couleur);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Catégorie ajoutée avec succès!";
            header('Location: categories.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de l'ajout de la catégorie : " . $conn->error;
        }
    }
}

// Gestion de la modification de catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_categorie'])) {
    $id_categorie = $_POST['id_categorie'];
    $nom_categorie = trim($_POST['nom_categorie']);
    $description = trim($_POST['description']);
    $statut = $_POST['statut'];
    $couleur = $_POST['couleur'] ?? '#3498db';

    // Vérifier si le nom existe déjà pour une autre catégorie
    $stmt_check = $conn->prepare("SELECT id_categorie FROM categories WHERE nom_categorie = ? AND id_categorie != ?");
    $stmt_check->bind_param("si", $nom_categorie, $id_categorie);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $error = "Une autre catégorie avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("UPDATE categories SET nom_categorie = ?, description = ?, statut = ?, couleur = ? WHERE id_categorie = ?");
        $stmt->bind_param("ssssi", $nom_categorie, $description, $statut, $couleur, $id_categorie);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Catégorie modifiée avec succès!";
            header('Location: categories.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de la modification de la catégorie : " . $conn->error;
        }
    }
}

// Gestion de la suppression de catégorie
if (isset($_GET['supprimer'])) {
    $id_categorie = $_GET['supprimer'];
    
    // Vérifier si la catégorie est utilisée par des produits
    $stmt_check = $conn->prepare("SELECT COUNT(*) as nb_produits FROM produits WHERE categorie_id = ?");
    $stmt_check->bind_param("i", $id_categorie);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $data = $result->fetch_assoc();
    
    if ($data['nb_produits'] > 0) {
        $error = "Impossible de supprimer cette catégorie : elle est utilisée par " . $data['nb_produits'] . " produit(s).";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id_categorie = ?");
        $stmt->bind_param("i", $id_categorie);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Catégorie supprimée avec succès!";
            header('Location: categories.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de la suppression de la catégorie : " . $conn->error;
        }
    }
}

// Récupérer une catégorie pour modification
$categorie_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_categorie = $_GET['modifier'];
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id_categorie = ?");
    $stmt->bind_param("i", $id_categorie);
    $stmt->execute();
    $result = $stmt->get_result();
    $categorie_a_modifier = $result->fetch_assoc();
}

// Récupérer le message de succès de la session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Gestion des Catégories</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --secondary: #3498db;
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
            color: var(--secondary);
            margin-right: 10px;
        }

        .logo h1 {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .welcome {
            padding: 20px;
            background: var(--light);
            margin: 20px;
            border-radius: 10px;
            text-align: center;
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

        .nav-item.active {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--secondary);
        }

        .nav-item:hover {
            background: rgba(52, 152, 219, 0.05);
            transform: translateX(5px);
        }

        .nav-item i {
            margin-right: 15px;
            font-size: 1.2rem;
            width: 20px;
            text-align: center;
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
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        /* Cards modernes */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
            color: white;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        /* Filtres modernes */
        .filters-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* Boutons modernes */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #229954);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #e67e22);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #2980b9);
            color: white;
        }

        /* Table moderne */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            position: sticky;
            top: 0;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .table tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: var(--success);
            color: white;
        }

        .badge-warning {
            background: var(--warning);
            color: white;
        }

        .category-color {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .page-link {
            padding: 8px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .page-link.active {
            background: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        .page-link:hover {
            background: var(--light);
            border-color: var(--secondary);
        }

        /* Modals modernes */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .modal-title {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
        }

        .close {
            color: var(--gray);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--dark);
        }

        .color-picker {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .color-option.selected {
            border-color: var(--dark);
            transform: scale(1.1);
        }

        /* Alertes */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            }
            
            .main-content {
                margin-left: 70px;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .stats-cards {
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
        <div class="header">
            <h1><i class="fas fa-tags me-2"></i>Gestion des Catégories</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <p style="font-weight: bold;"><?php echo $_SESSION['user_nom']; ?></p>
                    <small>Administrateur</small>
                </div>
            </div>
        </div>

        <!-- Messages d'alerte -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Cartes de statistiques -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--info);">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="stat-value"><?php echo $total_categories; ?></div>
                <div class="stat-label">Total Catégories</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $active_categories = array_filter($categories, function($cat) {
                        return ($cat['statut'] ?? 'actif') === 'actif';
                    });
                    echo count($active_categories); 
                    ?>
                </div>
                <div class="stat-label">Catégories Actives</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--warning);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $inactive_categories = array_filter($categories, function($cat) {
                        return ($cat['statut'] ?? 'actif') === 'inactif';
                    });
                    echo count($inactive_categories); 
                    ?>
                </div>
                <div class="stat-label">Catégories Inactives</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--secondary);">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-value"><?php echo array_sum($produits_par_categorie); ?></div>
                <div class="stat-label">Produits Total</div>
            </div>
        </div>

        <!-- Filtres et recherche -->
        <div class="filters-container">
            <form method="GET" class="filters-form">
                <div class="form-group">
                    <label class="form-label">Rechercher</label>
                    <input type="text" name="search" class="form-control" placeholder="Nom ou description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <option value="">Tous les statuts</option>
                        <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                        <option value="inactif" <?php echo $statut_filter === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Trier par</label>
                    <select name="sort" class="form-select">
                        <option value="nom_categorie" <?php echo $sort_by === 'nom_categorie' ? 'selected' : ''; ?>>Nom</option>
                        <option value="date_creation" <?php echo $sort_by === 'date_creation' ? 'selected' : ''; ?>>Date création</option>
                        <option value="statut" <?php echo $sort_by === 'statut' ? 'selected' : ''; ?>>Statut</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ordre</label>
                    <select name="order" class="form-select">
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Croissant</option>
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Décroissant</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-filter"></i> Appliquer les filtres
                    </button>
                    <a href="categories.php" class="btn" style="background: #6c757d; color: white; width: 100%; margin-top: 10px; display: block; text-align: center;">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- En-tête avec actions -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h2 style="color: var(--primary); margin: 0;">Liste des Catégories</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                    <i class="fas fa-plus"></i> Nouvelle Catégorie
                </button>
                <button class="btn btn-info" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Exporter CSV
                </button>
            </div>
        </div>

        <!-- Tableau des catégories -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Couleur</th>
                        <th>Nom de la Catégorie</th>
                        <th>Description</th>
                        <th>Produits</th>
                        <th>Statut</th>
                        <th>Date Création</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--gray);">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 10px; display: block;"></i>
                                Aucune catégorie trouvée
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $categorie): 
                            $nb_produits = $produits_par_categorie[$categorie['id_categorie']] ?? 0;
                            $couleur = $categorie['couleur'] ?? '#3498db';
                        ?>
                        <tr>
                            <td><strong>#<?php echo $categorie['id_categorie']; ?></strong></td>
                            <td>
                                <div class="category-color" style="background: <?php echo $couleur; ?>;"></div>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($categorie['nom_categorie']); ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($categorie['description'])): ?>
                                    <?php echo htmlspecialchars($categorie['description']); ?>
                                <?php else: ?>
                                    <span style="color: var(--gray); font-style: italic;">Aucune description</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--light); color: var(--dark);">
                                    <i class="fas fa-box"></i> <?php echo $nb_produits; ?> produit(s)
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo ($categorie['statut'] ?? 'actif') === 'actif' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo ($categorie['statut'] ?? 'actif') === 'actif' ? 'Actif' : 'Inactif'; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($categorie['date_creation'])); ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button class="btn btn-warning" onclick="modifierCategorie(<?php echo $categorie['id_categorie']; ?>)" style="padding: 8px 12px;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger" onclick="supprimerCategorie(<?php echo $categorie['id_categorie']; ?>)" style="padding: 8px 12px;">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($statut_filter); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Ajouter Catégorie -->
    <div id="modalAjouter" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-plus me-2"></i>Nouvelle Catégorie</h3>
                <button class="close" onclick="closeModal('modalAjouter')">&times;</button>
            </div>
            <form method="POST" id="formAjouter">
                <div class="form-group">
                    <label class="form-label">Nom de la catégorie *</label>
                    <input type="text" name="nom_categorie" class="form-control" required maxlength="100" placeholder="Ex: Électronique, Vêtements...">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" placeholder="Description de la catégorie..." maxlength="500" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur d'identification</label>
                    <div class="color-picker">
                        <?php 
                        $couleurs = ['#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#34495e', '#e67e22'];
                        foreach ($couleurs as $couleur): 
                        ?>
                        <div class="color-option" style="background: <?php echo $couleur; ?>" 
                             onclick="selectColor(this, '<?php echo $couleur; ?>')"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="couleur" id="couleur" value="#3498db">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="ajouter_categorie" class="btn btn-success">
                        <i class="fas fa-check"></i> Créer la catégorie
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier Catégorie -->
    <div id="modalModifier" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier la Catégorie</h3>
                <button class="close" onclick="closeModal('modalModifier')">&times;</button>
            </div>
            <?php if ($categorie_a_modifier): ?>
            <form method="POST" id="formModifier">
                <input type="hidden" name="id_categorie" value="<?php echo $categorie_a_modifier['id_categorie']; ?>">
                <div class="form-group">
                    <label class="form-label">Nom de la catégorie *</label>
                    <input type="text" name="nom_categorie" class="form-control" value="<?php echo htmlspecialchars($categorie_a_modifier['nom_categorie']); ?>" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" maxlength="500" rows="3"><?php echo htmlspecialchars($categorie_a_modifier['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur d'identification</label>
                    <div class="color-picker">
                        <?php 
                        $couleur_actuelle = $categorie_a_modifier['couleur'] ?? '#3498db';
                        foreach ($couleurs as $couleur): 
                        ?>
                        <div class="color-option <?php echo $couleur === $couleur_actuelle ? 'selected' : ''; ?>" 
                             style="background: <?php echo $couleur; ?>" 
                             onclick="selectColor(this, '<?php echo $couleur; ?>', 'modifier')"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="couleur" id="couleur_modifier" value="<?php echo $couleur_actuelle; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <option value="actif" <?php echo ($categorie_a_modifier['statut'] ?? 'actif') === 'actif' ? 'selected' : ''; ?>>Actif</option>
                        <option value="inactif" <?php echo ($categorie_a_modifier['statut'] ?? 'actif') === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal('modalModifier')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="modifier_categorie" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer les modifications
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Gestion des modals
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Sélection de couleur
        function selectColor(element, couleur, type = 'ajouter') {
            // Retirer la sélection précédente
            const parent = element.parentElement;
            parent.querySelectorAll('.color-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Ajouter la sélection
            element.classList.add('selected');
            
            // Mettre à jour le champ caché
            if (type === 'modifier') {
                document.getElementById('couleur_modifier').value = couleur;
            } else {
                document.getElementById('couleur').value = couleur;
            }
        }

        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Fonctions pour les actions
        function modifierCategorie(id) {
            window.location.href = 'categories.php?modifier=' + id;
        }

        function supprimerCategorie(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette catégorie ? Cette action est irréversible.')) {
                window.location.href = 'categories.php?supprimer=' + id;
            }
        }

        // Export CSV
        function exportToCSV() {
            // Simuler l'export CSV
            alert('Fonctionnalité d\'export CSV en cours de développement...');
        }

        // Ouvrir le modal de modification si une catégorie est à modifier
        <?php if ($categorie_a_modifier): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openModal('modalModifier');
            });
        <?php endif; ?>

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        // Auto-focus sur la recherche
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.focus();
            }
        });

        // Animation des cartes au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in-up');
            });
        });
    </script>
</body>
</html>