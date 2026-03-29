<?php
// Views/admin/commandes.php
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
$fournisseur_filter = $_GET['fournisseur'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$sort_by = $_GET['sort'] ?? 'date_commande';
$sort_order = $_GET['order'] ?? 'desc';
$page = $_GET['page'] ?? 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "c.id_commande LIKE ?";
    $params[] = "%$search%";
    $types .= 's';
}

if (!empty($statut_filter)) {
    $where_conditions[] = "c.statut = ?";
    $params[] = $statut_filter;
    $types .= 's';
}

if (!empty($fournisseur_filter)) {
    $where_conditions[] = "c.fournisseur_id = ?";
    $params[] = $fournisseur_filter;
    $types .= 'i';
}

if (!empty($date_debut)) {
    $where_conditions[] = "c.date_commande >= ?";
    $params[] = $date_debut;
    $types .= 's';
}

if (!empty($date_fin)) {
    $where_conditions[] = "c.date_commande <= ?";
    $params[] = $date_fin;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Compter le nombre total de commandes
$count_sql = "SELECT COUNT(*) as total FROM commandes c $where_sql";
if (!empty($params)) {
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_commandes = $result_count->fetch_assoc()['total'];
} else {
    $result_count = $conn->query($count_sql);
    $total_commandes = $result_count->fetch_assoc()['total'];
}

$total_pages = ceil($total_commandes / $limit);

// Ordre de tri sécurisé
$allowed_sorts = ['id_commande', 'date_commande', 'statut', 'nom_fournisseur'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'date_commande';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des commandes avec filtres et pagination
$commandes = [];
$sql = "SELECT c.*, f.nom as nom_fournisseur 
        FROM commandes c 
        LEFT JOIN fournisseurs f ON c.fournisseur_id = f.id_fournisseur 
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
$commandes = $result->fetch_all(MYSQLI_ASSOC);

// Récupération des fournisseurs pour le filtre
$fournisseurs = [];
$stmt_fournisseurs = $conn->query("SELECT id_fournisseur, nom FROM fournisseurs ORDER BY nom");
if ($stmt_fournisseurs) {
    $fournisseurs = $stmt_fournisseurs->fetch_all(MYSQLI_ASSOC);
}

// Compter le nombre de produits par commande
$produits_par_commande = [];
$stmt_count = $conn->query("
    SELECT commande_id, COUNT(*) as nb_produits, SUM(quantite) as total_quantite 
    FROM details_commande 
    GROUP BY commande_id
");
if ($stmt_count) {
    $counts = $stmt_count->fetch_all(MYSQLI_ASSOC);
    foreach ($counts as $count) {
        $produits_par_commande[$count['commande_id']] = [
            'nb_produits' => $count['nb_produits'],
            'total_quantite' => $count['total_quantite']
        ];
    }
}

// Calcul des statistiques
$commandes_en_attente = 0;
$commandes_confirmees = 0;
$commandes_livrees = 0;
$commandes_annulees = 0;

foreach ($commandes as $commande) {
    switch ($commande['statut']) {
        case 'en attente':
            $commandes_en_attente++;
            break;
        case 'confirmée':
            $commandes_confirmees++;
            break;
        case 'livrée':
            $commandes_livrees++;
            break;
        case 'annulée':
            $commandes_annulees++;
            break;
    }
}

// Gestion de l'ajout de commande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_commande'])) {
    $date_commande = $_POST['date_commande'];
    $fournisseur_id = !empty($_POST['fournisseur_id']) ? intval($_POST['fournisseur_id']) : NULL;
    $statut = $_POST['statut'];

    $stmt = $conn->prepare("INSERT INTO commandes (date_commande, fournisseur_id, statut) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $date_commande, $fournisseur_id, $statut);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Commande ajoutée avec succès!";
        header('Location: commandes.php?success=1');
        exit();
    } else {
        $_SESSION['error'] = "Erreur lors de l'ajout de la commande : " . $conn->error;
    }
}

// Gestion de la modification de commande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_commande'])) {
    $id_commande = $_POST['id_commande'];
    $date_commande = $_POST['date_commande'];
    $fournisseur_id = !empty($_POST['fournisseur_id']) ? intval($_POST['fournisseur_id']) : NULL;
    $statut = $_POST['statut'];

    $stmt = $conn->prepare("UPDATE commandes SET date_commande = ?, fournisseur_id = ?, statut = ? WHERE id_commande = ?");
    $stmt->bind_param("sisi", $date_commande, $fournisseur_id, $statut, $id_commande);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Commande modifiée avec succès!";
        header('Location: commandes.php?success=1');
        exit();
    } else {
        $_SESSION['error'] = "Erreur lors de la modification de la commande : " . $conn->error;
    }
}

// Gestion de la suppression de commande
if (isset($_GET['supprimer'])) {
    $id_commande = $_GET['supprimer'];

    // Vérifier si la commande a des détails
    $stmt_check = $conn->prepare("SELECT COUNT(*) as nb_details FROM details_commande WHERE commande_id = ?");
    $stmt_check->bind_param("i", $id_commande);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $data = $result->fetch_assoc();

    if ($data['nb_details'] > 0) {
        $_SESSION['error'] = "Impossible de supprimer cette commande : elle contient " . $data['nb_details'] . " produit(s). Supprimez d'abord les détails de la commande.";
    } else {
        $stmt = $conn->prepare("DELETE FROM commandes WHERE id_commande = ?");
        $stmt->bind_param("i", $id_commande);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Commande supprimée avec succès!";
            header('Location: commandes.php?success=1');
            exit();
        } else {
            $_SESSION['error'] = "Erreur lors de la suppression de la commande : " . $conn->error;
        }
    }
}

// Récupérer une commande pour modification
$commande_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_commande = $_GET['modifier'];
    $stmt = $conn->prepare("SELECT * FROM commandes WHERE id_commande = ?");
    $stmt->bind_param("i", $id_commande);
    $stmt->execute();
    $result = $stmt->get_result();
    $commande_a_modifier = $result->fetch_assoc();
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
    <title>STOCKFLOW | Gestion des Commandes</title>
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
        .stat-card.en-attente::before { background: var(--warning); }
        .stat-card.confirmee::before { background: var(--secondary); }
        .stat-card.livree::before { background: var(--success); }
        .stat-card.annulee::before { background: var(--danger); }

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
        .stat-icon.en-attente { background: var(--warning); }
        .stat-icon.confirmee { background: var(--secondary); }
        .stat-icon.livree { background: var(--success); }
        .stat-icon.annulee { background: var(--danger); }

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
            background: var(--secondary);
            color: white;
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
            <h1><i class="fas fa-shopping-cart me-2"></i>Gestion des Commandes</h1>
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
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-value"><?php echo $total_commandes; ?></div>
                <div class="stat-label">Total Commandes</div>
            </div>
            <div class="stat-card en-attente">
                <div class="stat-icon en-attente">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $commandes_en_attente; ?></div>
                <div class="stat-label">En Attente</div>
            </div>
            <div class="stat-card confirmee">
                <div class="stat-icon confirmee">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $commandes_confirmees; ?></div>
                <div class="stat-label">Confirmées</div>
            </div>
            <div class="stat-card livree">
                <div class="stat-icon livree">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-value"><?php echo $commandes_livrees; ?></div>
                <div class="stat-label">Livrées</div>
            </div>
            <div class="stat-card annulee">
                <div class="stat-icon annulee">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $commandes_annulees; ?></div>
                <div class="stat-label">Annulées</div>
            </div>
        </div>

        <!-- Filtres et recherche -->
        <div class="filters-container">
            <form method="GET" class="filters-form">
                <div class="form-group">
                    <label class="form-label">Rechercher (ID)</label>
                    <input type="text" name="search" class="form-control" placeholder="Numéro de commande..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <option value="">Tous les statuts</option>
                        <option value="en attente" <?php echo $statut_filter === 'en attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="confirmée" <?php echo $statut_filter === 'confirmée' ? 'selected' : ''; ?>>Confirmée</option>
                        <option value="livrée" <?php echo $statut_filter === 'livrée' ? 'selected' : ''; ?>>Livrée</option>
                        <option value="annulée" <?php echo $statut_filter === 'annulée' ? 'selected' : ''; ?>>Annulée</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Fournisseur</label>
                    <select name="fournisseur" class="form-select">
                        <option value="">Tous les fournisseurs</option>
                        <?php foreach ($fournisseurs as $fournisseur): ?>
                            <option value="<?php echo $fournisseur['id_fournisseur']; ?>" <?php echo $fournisseur_filter == $fournisseur['id_fournisseur'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fournisseur['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date début</label>
                    <input type="date" name="date_debut" class="form-control" value="<?php echo htmlspecialchars($date_debut); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Date fin</label>
                    <input type="date" name="date_fin" class="form-control" value="<?php echo htmlspecialchars($date_fin); ?>">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-filter"></i> Appliquer les filtres
                    </button>
                    <a href="commandes.php" class="btn" style="background: #6c757d; color: white; width: 100%; margin-top: 10px; display: block; text-align: center;">
                        <i class="fas fa-times"></i> Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- En-tête avec actions -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h2 style="color: var(--primary); margin: 0;">Liste des Commandes</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                    <i class="fas fa-plus"></i> Nouvelle Commande
                </button>
                <button class="btn btn-info" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Exporter CSV
                </button>
            </div>
        </div>

        <!-- Tableau des commandes -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date Commande</th>
                        <th>Fournisseur</th>
                        <th>Statut</th>
                        <th>Produits</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($commandes)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--gray);">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 10px; display: block;"></i>
                                Aucune commande trouvée
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($commandes as $commande):
                            $details = $produits_par_commande[$commande['id_commande']] ?? ['nb_produits' => 0, 'total_quantite' => 0];
                            
                            // Déterminer la classe du badge selon le statut
                            switch($commande['statut']) {
                                case 'en attente':
                                    $statut_class = 'badge-warning';
                                    break;
                                case 'confirmée':
                                    $statut_class = 'badge-info';
                                    break;
                                case 'livrée':
                                    $statut_class = 'badge-success';
                                    break;
                                case 'annulée':
                                    $statut_class = 'badge-danger';
                                    break;
                                default:
                                    $statut_class = 'badge-secondary';
                            }
                        ?>
                        <tr>
                            <td><strong>#<?php echo $commande['id_commande']; ?></strong></td>
                            <td>
                                <?php echo date('d/m/Y', strtotime($commande['date_commande'])); ?>
                                <br>
                                <small style="color: var(--gray);"><?php echo date('H:i', strtotime($commande['date_commande'])); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($commande['nom_fournisseur'])): ?>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($commande['nom_fournisseur']); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--gray); font-style: italic;">Aucun fournisseur</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $statut_class; ?>">
                                    <?php echo ucfirst($commande['statut']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($details['nb_produits'] > 0): ?>
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-box"></i> 
                                        <?php echo $details['nb_produits']; ?> produit(s)
                                    </span>
                                    <br>
                                    <small style="color: var(--gray);">
                                        <?php echo $details['total_quantite']; ?> unité(s)
                                    </small>
                                <?php else: ?>
                                    <span style="color: var(--gray); font-style: italic;">Aucun produit</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <a href="details_commande.php?commande=<?php echo $commande['id_commande']; ?>" class="btn btn-info" style="padding: 8px 12px;">
                                        <i class="fas fa-list"></i> Détails
                                    </a>
                                    <button class="btn btn-warning" onclick="modifierCommande(<?php echo $commande['id_commande']; ?>)" style="padding: 8px 12px;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger" onclick="supprimerCommande(<?php echo $commande['id_commande']; ?>)" style="padding: 8px 12px;">
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
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($statut_filter); ?>&fournisseur=<?php echo urlencode($fournisseur_filter); ?>&date_debut=<?php echo urlencode($date_debut); ?>&date_fin=<?php echo urlencode($date_fin); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Ajouter Commande -->
    <div id="modalAjouter" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-plus me-2"></i>Nouvelle Commande</h3>
                <button class="close" onclick="closeModal('modalAjouter')">&times;</button>
            </div>
            <form method="POST" id="formAjouter">
                <div class="form-group">
                    <label class="form-label">Date de commande *</label>
                    <input type="date" name="date_commande" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Fournisseur</label>
                    <select name="fournisseur_id" class="form-select">
                        <option value="">Aucun fournisseur</option>
                        <?php foreach ($fournisseurs as $fournisseur): ?>
                            <option value="<?php echo $fournisseur['id_fournisseur']; ?>">
                                <?php echo htmlspecialchars($fournisseur['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Statut *</label>
                    <select name="statut" class="form-select" required>
                        <option value="en attente" selected>En attente</option>
                        <option value="confirmée">Confirmée</option>
                        <option value="livrée">Livrée</option>
                        <option value="annulée">Annulée</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--light);">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="ajouter_commande" class="btn btn-success">
                        <i class="fas fa-check"></i> Créer la commande
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier Commande -->
    <div id="modalModifier" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier la Commande</h3>
                <button class="close" onclick="closeModal('modalModifier')">&times;</button>
            </div>
            <?php if ($commande_a_modifier): ?>
            <form method="POST" id="formModifier">
                <input type="hidden" name="id_commande" value="<?php echo $commande_a_modifier['id_commande']; ?>">
                <div class="form-group">
                    <label class="form-label">Date de commande *</label>
                    <input type="date" name="date_commande" class="form-control" value="<?php echo $commande_a_modifier['date_commande']; ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Fournisseur</label>
                    <select name="fournisseur_id" class="form-select">
                        <option value="">Aucun fournisseur</option>
                        <?php foreach ($fournisseurs as $fournisseur): ?>
                            <option value="<?php echo $fournisseur['id_fournisseur']; ?>" <?php echo ($commande_a_modifier['fournisseur_id'] ?? '') == $fournisseur['id_fournisseur'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fournisseur['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Statut *</label>
                    <select name="statut" class="form-select" required>
                        <option value="en attente" <?php echo ($commande_a_modifier['statut'] ?? '') === 'en attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="confirmée" <?php echo ($commande_a_modifier['statut'] ?? '') === 'confirmée' ? 'selected' : ''; ?>>Confirmée</option>
                        <option value="livrée" <?php echo ($commande_a_modifier['statut'] ?? '') === 'livrée' ? 'selected' : ''; ?>>Livrée</option>
                        <option value="annulée" <?php echo ($commande_a_modifier['statut'] ?? '') === 'annulée' ? 'selected' : ''; ?>>Annulée</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--light);">
                    <button type="button" class="btn" onclick="closeModal('modalModifier')" style="background: #6c757d; color: white;">Annuler</button>
                    <button type="submit" name="modifier_commande" class="btn btn-success">
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
        function modifierCommande(id) {
            window.location.href = 'commandes.php?modifier=' + id;
        }

        function supprimerCommande(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette commande ? Cette action est irréversible.')) {
                window.location.href = 'commandes.php?supprimer=' + id;
            }
        }

        // Export CSV
        function exportToCSV() {
            alert('Fonctionnalité d\'export CSV en cours de développement...');
        }

        // Ouvrir le modal de modification si une commande est à modifier
        <?php if ($commande_a_modifier): ?>
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