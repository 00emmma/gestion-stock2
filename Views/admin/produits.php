<?php
// Views/admin/produits.php
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
$categorie_filter = $_GET['categorie'] ?? '';
$statut_stock = $_GET['statut_stock'] ?? '';
$sort_by = $_GET['sort'] ?? 'nom_produit';
$sort_order = $_GET['order'] ?? 'asc';
$page = $_GET['page'] ?? 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(p.nom_produit LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if (!empty($categorie_filter)) {
    $where_conditions[] = "p.categorie_id = ?";
    $params[] = $categorie_filter;
    $types .= 'i';
}

if (!empty($statut_stock)) {
    if ($statut_stock === 'faible') {
        $where_conditions[] = "p.quantite <= 10";
    } elseif ($statut_stock === 'rupture') {
        $where_conditions[] = "p.quantite = 0";
    } elseif ($statut_stock === 'normal') {
        $where_conditions[] = "p.quantite > 10";
    }
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Compter le nombre total de produits
$count_sql = "SELECT COUNT(*) as total FROM produits p $where_sql";
if (!empty($params)) {
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_produits = $result_count->fetch_assoc()['total'];
} else {
    $result_count = $conn->query($count_sql);
    $total_produits = $result_count->fetch_assoc()['total'];
}

$total_pages = ceil($total_produits / $limit);

// Ordre de tri sécurisé
$allowed_sorts = ['nom_produit', 'prix_unitaire', 'quantite', 'id_produit'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'nom_produit';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des produits avec filtres et pagination (CORRIGÉ : retirer c.couleur)
$produits = [];
$sql = "SELECT p.*, c.nom_categorie 
        FROM produits p 
        LEFT JOIN categories c ON p.categorie_id = c.id_categorie 
        $where_sql 
        ORDER BY $sort_by $sort_order 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$produits = $result->fetch_all(MYSQLI_ASSOC);

// Récupération des catégories pour le filtre (CORRIGÉ : retirer couleur)
$categories = [];
$stmt_categories = $conn->query("SELECT id_categorie, nom_categorie FROM categories ORDER BY nom_categorie");
if ($stmt_categories) {
    $categories = $stmt_categories->fetch_all(MYSQLI_ASSOC);
}

// Calcul des statistiques
$produits_alerte = 0;
$produits_rupture = 0;
$valeur_total_stock = 0;

foreach ($produits as $produit) {
    if ($produit['quantite'] < 10 && $produit['quantite'] > 0) {
        $produits_alerte++;
    }
    if ($produit['quantite'] == 0) {
        $produits_rupture++;
    }
    $valeur_total_stock += $produit['prix_unitaire'] * $produit['quantite'];
}

// Gestion de l'ajout de produit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_produit'])) {
    $nom_produit = trim($_POST['nom_produit']);
    $description = trim($_POST['description']);
    $prix_unitaire = floatval($_POST['prix_unitaire']);
    $quantite = intval($_POST['quantite']);
    $seuil_alerte = intval($_POST['seuil_alerte']);
    $categorie_id = !empty($_POST['categorie_id']) ? intval($_POST['categorie_id']) : NULL;

    // Vérifier si le produit existe déjà
    $stmt_check = $conn->prepare("SELECT id_produit FROM produits WHERE nom_produit = ?");
    $stmt_check->bind_param("s", $nom_produit);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $_SESSION['error'] = "Un produit avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("INSERT INTO produits (nom_produit, description, prix_unitaire, quantite, seuil_alerte, categorie_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdiii", $nom_produit, $description, $prix_unitaire, $quantite, $seuil_alerte, $categorie_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Produit ajouté avec succès!";
            header('Location: produits.php?success=1');
            exit();
        } else {
            $_SESSION['error'] = "Erreur lors de l'ajout du produit : " . $conn->error;
        }
    }
}

// Gestion de la modification de produit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_produit'])) {
    $id_produit = $_POST['id_produit'];
    $nom_produit = trim($_POST['nom_produit']);
    $description = trim($_POST['description']);
    $prix_unitaire = floatval($_POST['prix_unitaire']);
    $quantite = intval($_POST['quantite']);
    $seuil_alerte = intval($_POST['seuil_alerte']);
    $categorie_id = !empty($_POST['categorie_id']) ? intval($_POST['categorie_id']) : NULL;

    // Vérifier si le nom existe déjà pour un autre produit
    $stmt_check = $conn->prepare("SELECT id_produit FROM produits WHERE nom_produit = ? AND id_produit != ?");
    $stmt_check->bind_param("si", $nom_produit, $id_produit);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $_SESSION['error'] = "Un autre produit avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("UPDATE produits SET nom_produit = ?, description = ?, prix_unitaire = ?, quantite = ?, seuil_alerte = ?, categorie_id = ? WHERE id_produit = ?");
        $stmt->bind_param("ssdiiii", $nom_produit, $description, $prix_unitaire, $quantite, $seuil_alerte, $categorie_id, $id_produit);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Produit modifié avec succès!";
            header('Location: produits.php?success=1');
            exit();
        } else {
            $_SESSION['error'] = "Erreur lors de la modification du produit : " . $conn->error;
        }
    }
}

// Gestion de la suppression de produit
if (isset($_GET['supprimer'])) {
    $id_produit = $_GET['supprimer'];
    
    // Vérifier si le produit est utilisé dans des commandes
    $stmt_check = $conn->prepare("SELECT COUNT(*) as nb_commandes FROM details_commande WHERE produit_id = ?");
    $stmt_check->bind_param("i", $id_produit);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $data = $result->fetch_assoc();
    
    if ($data['nb_commandes'] > 0) {
        $_SESSION['error'] = "Impossible de supprimer ce produit : il est utilisé dans " . $data['nb_commandes'] . " commande(s).";
    } else {
        $stmt = $conn->prepare("DELETE FROM produits WHERE id_produit = ?");
        $stmt->bind_param("i", $id_produit);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Produit supprimé avec succès!";
            header('Location: produits.php?success=1');
            exit();
        } else {
            $_SESSION['error'] = "Erreur lors de la suppression du produit : " . $conn->error;
        }
    }
}

// Récupérer un produit pour modification
$produit_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_produit = $_GET['modifier'];
    $stmt = $conn->prepare("SELECT * FROM produits WHERE id_produit = ?");
    $stmt->bind_param("i", $id_produit);
    $stmt->execute();
    $result = $stmt->get_result();
    $produit_a_modifier = $result->fetch_assoc();
}

// Récupérer les messages de session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Gestion des Produits</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        /* Cartes de statistiques */
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
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.total::before { background: var(--info); }
        .stat-card.alert::before { background: var(--warning); }
        .stat-card.rupture::before { background: var(--danger); }
        .stat-card.valeur::before { background: var(--success); }

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

        .stat-icon.total { background: var(--info); }
        .stat-icon.alert { background: var(--warning); }
        .stat-icon.rupture { background: var(--danger); }
        .stat-icon.valeur { background: var(--success); }

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

        .badge-danger {
            background: var(--danger);
            color: white;
        }

        .badge-info {
            background: var(--info);
            color: white;
        }

        .badge-secondary {
            background: var(--gray);
            color: white;
        }

        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            background: #e9ecef;
            color: var(--dark);
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
            max-width: 600px;
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

        /* Indicateur de stock */
        .stock-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stock-bar {
            width: 60px;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }

        .stock-fill {
            height: 100%;
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        .stock-fill.high { background: var(--success); }
        .stock-fill.medium { background: var(--warning); }
        .stock-fill.low { background: var(--danger); }

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

            .table {
                font-size: 12px;
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
            <h1><i class="fas fa-boxes me-2"></i>Gestion des Produits</h1>
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
            <div class="stat-card total">
                <div class="stat-icon total">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-value"><?php echo $total_produits; ?></div>
                <div class="stat-label">Total Produits</div>
            </div>
            <div class="stat-card alert">
                <div class="stat-icon alert">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?php echo $produits_alerte; ?></div>
                <div class="stat-label">Stock Faible</div>
            </div>
            <div class="stat-card rupture">
                <div class="stat-icon rupture">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $produits_rupture; ?></div>
                <div class="stat-label">En Rupture</div>
            </div>
            <div class="stat-card valeur">
                <div class="stat-icon valeur">
                    <i class="fas fa-euro-sign"></i>
                </div>
                <div class="stat-value"><?php echo number_format($valeur_total_stock, 0, ',', ' '); ?> €</div>
                <div class="stat-label">Valeur Stock</div>
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
                    <label class="form-label">Catégorie</label>
                    <select name="categorie" class="form-select">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?php echo $categorie['id_categorie']; ?>" <?php echo $categorie_filter == $categorie['id_categorie'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categorie['nom_categorie']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Statut Stock</label>
                    <select name="statut_stock" class="form-select">
                        <option value="">Tous les statuts</option>
                        <option value="normal" <?php echo $statut_stock === 'normal' ? 'selected' : ''; ?>>Stock Normal</option>
                        <option value="faible" <?php echo $statut_stock === 'faible' ? 'selected' : ''; ?>>Stock Faible</option>
                        <option value="rupture" <?php echo $statut_stock === 'rupture' ? 'selected' : ''; ?>>En Rupture</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Trier par</label>
                    <select name="sort" class="form-select">
                        <option value="nom_produit" <?php echo $sort_by === 'nom_produit' ? 'selected' : ''; ?>>Nom</option>
                        <option value="prix_unitaire" <?php echo $sort_by === 'prix_unitaire' ? 'selected' : ''; ?>>Prix</option>
                        <option value="quantite" <?php echo $sort_by === 'quantite' ? 'selected' : ''; ?>>Quantité</option>
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
                    <a href="produits.php" class="btn" style="background: #6c757d; color: white; width: 100%; margin-top: 10px; display: block; text-align: center;">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- En-tête avec actions -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h2 style="color: var(--primary); margin: 0;">Liste des Produits</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                    <i class="fas fa-plus"></i> Nouveau Produit
                </button>
                <button class="btn btn-info" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Exporter CSV
                </button>
            </div>
        </div>

        <!-- Tableau des produits -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Produit</th>
                        <th>Catégorie</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produits)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--gray);">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 10px; display: block;"></i>
                                Aucun produit trouvé
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($produits as $produit): 
                            $seuil_alerte = $produit['seuil_alerte'] ?? 10;
                            $pourcentage_stock = min(100, ($produit['quantite'] / ($seuil_alerte * 2)) * 100);
                            
                            if ($produit['quantite'] == 0) {
                                $statut_class = 'badge-danger';
                                $statut_text = 'Rupture';
                                $stock_class = 'low';
                            } elseif ($produit['quantite'] <= $seuil_alerte) {
                                $statut_class = 'badge-warning';
                                $statut_text = 'Faible';
                                $stock_class = 'low';
                            } else {
                                $statut_class = 'badge-success';
                                $statut_text = 'Normal';
                                $stock_class = $pourcentage_stock > 70 ? 'high' : 'medium';
                            }
                        ?>
                        <tr>
                            <td><strong>#<?php echo $produit['id_produit']; ?></strong></td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($produit['nom_produit']); ?></strong>
                                    <?php if (!empty($produit['description'])): ?>
                                        <div style="font-size: 12px; color: var(--gray); margin-top: 4px;">
                                            <?php echo substr(htmlspecialchars($produit['description']), 0, 50); ?>...
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($produit['nom_categorie'])): ?>
                                    <span class="category-badge">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($produit['nom_categorie']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--gray); font-style: italic;">Aucune</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: var(--success);"><?php echo number_format($produit['prix_unitaire'], 2, ',', ' '); ?> €</strong>
                            </td>
                            <td>
                                <div class="stock-indicator">
                                    <span style="font-weight: 600; min-width: 30px;"><?php echo $produit['quantite']; ?></span>
                                    <div class="stock-bar">
                                        <div class="stock-fill <?php echo $stock_class; ?>" style="width: <?php echo $pourcentage_stock; ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo $statut_class; ?>">
                                    <?php echo $statut_text; ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button class="btn btn-warning" onclick="modifierProduit(<?php echo $produit['id_produit']; ?>)" style="padding: 8px 12px;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger" onclick="supprimerProduit(<?php echo $produit['id_produit']; ?>)" style="padding: 8px 12px;">
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
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&categorie=<?php echo urlencode($categorie_filter); ?>&statut_stock=<?php echo urlencode($statut_stock); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Ajouter Produit -->
    <div id="modalAjouter" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-plus me-2"></i>Nouveau Produit</h3>
                <button class="close" onclick="closeModal('modalAjouter')">&times;</button>
            </div>
            <form method="POST" id="formAjouter">
                <div class="form-group">
                    <label class="form-label">Nom du produit *</label>
                    <input type="text" name="nom_produit" class="form-control" required maxlength="100" placeholder="Ex: iPhone 13, Samsung Galaxy...">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" placeholder="Description du produit..." maxlength="500" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Prix unitaire (€) *</label>
                    <input type="number" name="prix_unitaire" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Quantité initiale *</label>
                    <input type="number" name="quantite" class="form-control" min="0" required value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Seuil d'alerte stock</label>
                    <input type="number" name="seuil_alerte" class="form-control" min="1" value="10">
                    <small style="color: var(--gray);">Alerte lorsque le stock est inférieur à cette valeur</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Catégorie</label>
                    <select name="categorie_id" class="form-select">
                        <option value="">Aucune catégorie</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?php echo $categorie['id_categorie']; ?>">
                                <?php echo htmlspecialchars($categorie['nom_categorie']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--light);">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="ajouter_produit" class="btn btn-success">
                        <i class="fas fa-check"></i> Créer le produit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier Produit -->
    <div id="modalModifier" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier le Produit</h3>
                <button class="close" onclick="closeModal('modalModifier')">&times;</button>
            </div>
            <?php if ($produit_a_modifier): ?>
            <form method="POST" id="formModifier">
                <input type="hidden" name="id_produit" value="<?php echo $produit_a_modifier['id_produit']; ?>">
                <div class="form-group">
                    <label class="form-label">Nom du produit *</label>
                    <input type="text" name="nom_produit" class="form-control" value="<?php echo htmlspecialchars($produit_a_modifier['nom_produit']); ?>" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" maxlength="500" rows="3"><?php echo htmlspecialchars($produit_a_modifier['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Prix unitaire (€) *</label>
                    <input type="number" name="prix_unitaire" class="form-control" step="0.01" min="0" value="<?php echo $produit_a_modifier['prix_unitaire']; ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantité *</label>
                    <input type="number" name="quantite" class="form-control" min="0" value="<?php echo $produit_a_modifier['quantite']; ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Seuil d'alerte stock</label>
                    <input type="number" name="seuil_alerte" class="form-control" min="1" value="<?php echo $produit_a_modifier['seuil_alerte'] ?? 10; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Catégorie</label>
                    <select name="categorie_id" class="form-select">
                        <option value="">Aucune catégorie</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?php echo $categorie['id_categorie']; ?>" <?php echo ($produit_a_modifier['categorie_id'] ?? '') == $categorie['id_categorie'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categorie['nom_categorie']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--light);">
                    <button type="button" class="btn" onclick="closeModal('modalModifier')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="modifier_produit" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer
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

        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Fonctions pour les actions
        function modifierProduit(id) {
            window.location.href = 'produits.php?modifier=' + id;
        }

        function supprimerProduit(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce produit ? Cette action est irréversible.')) {
                window.location.href = 'produits.php?supprimer=' + id;
            }
        }

        // Export CSV
        function exportToCSV() {
            // Simuler l'export CSV
            alert('Fonctionnalité d\'export CSV en cours de développement...');
        }

        // Ouvrir le modal de modification si un produit est à modifier
        <?php if ($produit_a_modifier): ?>
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
    </script>
</body>
</html>