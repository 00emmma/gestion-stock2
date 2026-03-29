<?php
// Views/admin/details_commande.php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../../index.php');
    exit();
}

// Connexion à la base de données
$conn = new mysqli("localhost", "root", "", "stoch_db");
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}

// Variables pour la recherche et le filtrage
$search = $_GET['search'] ?? '';
$commande_filter = $_GET['commande'] ?? '';
$produit_filter = $_GET['produit'] ?? '';
$sort_by = $_GET['sort'] ?? 'id_detail';
$sort_order = $_GET['order'] ?? 'desc';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(p.nom_produit LIKE ? OR c.id_commande LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if (!empty($commande_filter)) {
    $where_conditions[] = "dc.commande_id = ?";
    $params[] = $commande_filter;
    $types .= 'i';
}

if (!empty($produit_filter)) {
    $where_conditions[] = "dc.produit_id = ?";
    $params[] = $produit_filter;
    $types .= 'i';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Ordre de tri sécurisé
$allowed_sorts = ['id_detail', 'commande_id', 'nom_produit', 'quantite', 'prix_total', 'date_commande'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'id_detail';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des détails de commandes avec filtres
$details_commandes = [];
$sql = "SELECT dc.*, p.nom_produit, p.prix_unitaire, c.date_commande, f.nom as nom_fournisseur
        FROM details_commande dc 
        JOIN produits p ON dc.produit_id = p.id_produit 
        JOIN commandes c ON dc.commande_id = c.id_commande
        LEFT JOIN fournisseurs f ON c.fournisseur_id = f.id_fournisseur
        $where_sql 
        ORDER BY $sort_by $sort_order";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $details_commandes = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt_details = $conn->query($sql);
    if ($stmt_details) {
        $details_commandes = $stmt_details->fetch_all(MYSQLI_ASSOC);
    }
}

// Récupération des commandes pour le filtre
$commandes = [];
$stmt_commandes = $conn->query("SELECT id_commande FROM commandes ORDER BY id_commande DESC");
if ($stmt_commandes) {
    $commandes = $stmt_commandes->fetch_all(MYSQLI_ASSOC);
}

// Récupération des produits pour le filtre
$produits = [];
$stmt_produits = $conn->query("SELECT id_produit, nom_produit FROM produits ORDER BY nom_produit");
if ($stmt_produits) {
    $produits = $stmt_produits->fetch_all(MYSQLI_ASSOC);
}

// Récupération des statistiques pour les graphiques
$stats_mouvements = [];
$sql_stats = "SELECT 
    COUNT(*) as total_lignes,
    SUM(dc.quantite) as total_quantite,
    SUM(dc.prix_total) as total_montant,
    COUNT(DISTINCT dc.commande_id) as commandes_actives,
    COUNT(DISTINCT dc.produit_id) as produits_commandes,
    AVG(dc.quantite) as moyenne_quantite,
    MAX(dc.prix_total) as commande_max,
    MIN(dc.prix_total) as commande_min
FROM details_commande dc";

$result_stats = $conn->query($sql_stats);
if ($result_stats) {
    $stats_mouvements = $result_stats->fetch_assoc();
}

// Statistiques par mois pour le graphique d'évolution
$stats_evolution = [];
$sql_evolution = "SELECT 
    DATE_FORMAT(c.date_commande, '%Y-%m') as mois,
    COUNT(*) as nb_lignes,
    SUM(dc.quantite) as quantite_totale,
    SUM(dc.prix_total) as montant_total
FROM details_commande dc
JOIN commandes c ON dc.commande_id = c.id_commande
GROUP BY DATE_FORMAT(c.date_commande, '%Y-%m')
ORDER BY mois DESC
LIMIT 12";

$result_evolution = $conn->query($sql_evolution);
if ($result_evolution) {
    $stats_evolution = $result_evolution->fetch_all(MYSQLI_ASSOC);
}

// Top 5 des produits les plus commandés
$top_produits = [];
$sql_top_produits = "SELECT 
    p.nom_produit,
    SUM(dc.quantite) as quantite_totale,
    SUM(dc.prix_total) as montant_total
FROM details_commande dc
JOIN produits p ON dc.produit_id = p.id_produit
GROUP BY p.id_produit, p.nom_produit
ORDER BY quantite_totale DESC
LIMIT 5";

$result_top_produits = $conn->query($sql_top_produits);
if ($result_top_produits) {
    $top_produits = $result_top_produits->fetch_all(MYSQLI_ASSOC);
}

// Calcul des statistiques pour les graphiques en temps réel
$total_details = count($details_commandes);
$total_quantite = 0;
$total_montant = 0;

foreach ($details_commandes as $detail) {
    $total_quantite += $detail['quantite'];
    $total_montant += $detail['prix_total'];
}

// Gestion de l'ajout de détail de commande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_detail'])) {
    $commande_id = intval($_POST['commande_id']);
    $produit_id = intval($_POST['produit_id']);
    $quantite = intval($_POST['quantite']);

    if ($quantite <= 0) {
        $error = "La quantité doit être supérieure à 0.";
    } else {
        // Récupérer le prix unitaire du produit
        $stmt_prix = $conn->prepare("SELECT prix_unitaire FROM produits WHERE id_produit = ?");
        $stmt_prix->bind_param("i", $produit_id);
        $stmt_prix->execute();
        $result_prix = $stmt_prix->get_result();
        $produit = $result_prix->fetch_assoc();

        if ($produit) {
            $prix_total = $produit['prix_unitaire'] * $quantite;

            // Vérifier si le produit est déjà dans la commande
            $stmt_check = $conn->prepare("SELECT id_detail FROM details_commande WHERE commande_id = ? AND produit_id = ?");
            $stmt_check->bind_param("ii", $commande_id, $produit_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $error = "Ce produit est déjà dans cette commande.";
            } else {
                $stmt = $conn->prepare("INSERT INTO details_commande (commande_id, produit_id, quantite, prix_total) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiid", $commande_id, $produit_id, $quantite, $prix_total);

                if ($stmt->execute()) {
                    $success = "Détail de commande ajouté avec succès!";
                    header('Location: details_commande.php?success=1');
                    exit();
                } else {
                    $error = "Erreur lors de l'ajout : " . $conn->error;
                }
            }
        } else {
            $error = "Produit non trouvé.";
        }
    }
}

// Gestion de la modification de détail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_detail'])) {
    $id_detail = $_POST['id_detail'];
    $quantite = intval($_POST['quantite']);

    if ($quantite <= 0) {
        $error = "La quantité doit être supérieure à 0.";
    } else {
        // Récupérer le prix unitaire du produit
        $stmt_prix = $conn->prepare("
            SELECT p.prix_unitaire 
            FROM details_commande dc 
            JOIN produits p ON dc.produit_id = p.id_produit 
            WHERE dc.id_detail = ?
        ");
        $stmt_prix->bind_param("i", $id_detail);
        $stmt_prix->execute();
        $result_prix = $stmt_prix->get_result();
        $detail = $result_prix->fetch_assoc();

        if ($detail) {
            $prix_total = $detail['prix_unitaire'] * $quantite;

            $stmt = $conn->prepare("UPDATE details_commande SET quantite = ?, prix_total = ? WHERE id_detail = ?");
            $stmt->bind_param("idi", $quantite, $prix_total, $id_detail);

            if ($stmt->execute()) {
                $success = "Détail modifié avec succès!";
                header('Location: details_commande.php?success=1');
                exit();
            } else {
                $error = "Erreur lors de la modification : " . $conn->error;
            }
        }
    }
}

// Gestion de la suppression de détail
if (isset($_GET['supprimer'])) {
    $id_detail = $_GET['supprimer'];

    $stmt = $conn->prepare("DELETE FROM details_commande WHERE id_detail = ?");
    $stmt->bind_param("i", $id_detail);

    if ($stmt->execute()) {
        $success = "Détail supprimé avec succès!";
        header('Location: details_commande.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la suppression : " . $conn->error;
    }
}

// Récupérer un détail pour modification
$detail_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_detail = $_GET['modifier'];
    $stmt = $conn->prepare("
        SELECT dc.*, p.nom_produit, p.prix_unitaire, c.id_commande
        FROM details_commande dc 
        JOIN produits p ON dc.produit_id = p.id_produit 
        JOIN commandes c ON dc.commande_id = c.id_commande
        WHERE dc.id_detail = ?
    ");
    $stmt->bind_param("i", $id_detail);
    $stmt->execute();
    $result = $stmt->get_result();
    $detail_a_modifier = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Détails des Commandes</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
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
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
        }

        .sidebar {
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: var(--dark);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }

        .logo {
            display: flex;
            align-items: center;
            padding: 0 20px 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .logo h1 {
            font-size: 24px;
            margin-left: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
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
            border-radius: 0 12px 12px 0;
            margin: 5px 0;
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
            transform: translateX(5px);
        }

        .nav-item i {
            margin-right: 12px;
            font-size: 18px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .nav-item:hover i,
        .nav-item.active i {
            transform: scale(1.1);
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }

        .content-wrapper {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            min-height: calc(100vh - 60px);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .header h1 {
            color: var(--primary);
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        /* Styles modernisés pour les composants */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
        }

        /* Statistiques */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 25px;
            color: white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-icon {
            font-size: 40px;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 16px;
            opacity: 0.9;
        }

        /* Graphiques */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            color: var(--primary);
            font-size: 1.2rem;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Tableaux modernisés */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-weight: 600;
            padding: 15px;
            text-align: left;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid rgba(67, 97, 238, 0.1);
        }

        .table tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Badges modernisés */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .badge-info {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
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

        .stat-card,
        .chart-card,
        .table-container {
            animation: fadeInUp 0.6s ease-out;
        }

        .stat-card:nth-child(2) {
            animation-delay: 0.1s;
        }

        .stat-card:nth-child(3) {
            animation-delay: 0.2s;
        }

        .stat-card:nth-child(4) {
            animation-delay: 0.3s;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .logo h1,
            .welcome,
            .nav-item span {
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

            .charts-container {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 250px;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        /* Filtres modernisés */
        .filters-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
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

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(114, 9, 183, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            background-color: white;
            transition: all 0.3s ease;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(114, 9, 183, 0.1);
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        <div class="content-wrapper">
            <div class="header">
                <h1>Détails des Commandes</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 600; color: var(--primary);"><?php echo $_SESSION['utilisateur']['nom']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Messages d'alerte -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);">
                    <i class="fas fa-check-circle"></i> Opération effectuée avec succès!
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Statistiques -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['total_lignes'] ?? 0; ?></div>
                    <div class="stat-label">Lignes de Commandes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['total_quantite'] ?? 0; ?></div>
                    <div class="stat-label">Quantité Totale</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-euro-sign"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats_mouvements['total_montant'] ?? 0, 0, ',', ' '); ?> €</div>
                    <div class="stat-label">Montant Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['produits_commandes'] ?? 0; ?></div>
                    <div class="stat-label">Produits Commandés</div>
                </div>
            </div>

            <!-- Graphiques de mouvement en temps réel -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Évolution des Commandes (12 mois)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Top 5 Produits les Plus Commandés</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="topProduitsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- En-tête de page -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Liste des Détails de Commandes</h2>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                        <i class="fas fa-plus"></i> Nouveau Détail
                    </button>
                    <a href="?export=1" class="btn" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white;">
                        <i class="fas fa-download"></i> Exporter CSV
                    </a>
                </div>
            </div>

            <!-- Filtres et recherche -->
            <div class="filters-container">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Rechercher</label>
                        <input type="text" name="search" class="form-control" placeholder="Produit ou ID commande..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Commande</label>
                        <select name="commande" class="form-select">
                            <option value="">Toutes les commandes</option>
                            <?php foreach ($commandes as $commande): ?>
                                <option value="<?php echo $commande['id_commande']; ?>" <?php echo $commande_filter == $commande['id_commande'] ? 'selected' : ''; ?>>
                                    Commande #<?php echo $commande['id_commande']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Produit</label>
                        <select name="produit" class="form-select">
                            <option value="">Tous les produits</option>
                            <?php foreach ($produits as $produit): ?>
                                <option value="<?php echo $produit['id_produit']; ?>" <?php echo $produit_filter == $produit['id_produit'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($produit['nom_produit']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Trier par</label>
                        <select name="sort" class="form-select">
                            <option value="id_detail" <?php echo $sort_by === 'id_detail' ? 'selected' : ''; ?>>ID</option>
                            <option value="commande_id" <?php echo $sort_by === 'commande_id' ? 'selected' : ''; ?>>Commande</option>
                            <option value="nom_produit" <?php echo $sort_by === 'nom_produit' ? 'selected' : ''; ?>>Produit</option>
                            <option value="quantite" <?php echo $sort_by === 'quantite' ? 'selected' : ''; ?>>Quantité</option>
                            <option value="prix_total" <?php echo $sort_by === 'prix_total' ? 'selected' : ''; ?>>Prix Total</option>
                            <option value="date_commande" <?php echo $sort_by === 'date_commande' ? 'selected' : ''; ?>>Date</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ordre</label>
                        <select name="order" class="form-select">
                            <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Décroissant</option>
                            <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Croissant</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filtrer
                        </button>
                        <a href="details_commande.php" class="btn" style="background: linear-gradient(135deg, #6c757d, #495057); color: white; margin-left: 10px;">
                            <i class="fas fa-times"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tableau des détails de commandes -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Commande</th>
                            <th>Date</th>
                            <th>Produit</th>
                            <th>Prix Unitaire</th>
                            <th>Quantité</th>
                            <th>Prix Total</th>
                            <th>Fournisseur</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($details_commandes)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 30px; color: var(--gray);">Aucun détail de commande trouvé</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($details_commandes as $detail): ?>
                                <tr>
                                    <td><?php echo $detail['id_detail']; ?></td>
                                    <td>
                                        <strong>#<?php echo $detail['commande_id']; ?></strong>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($detail['date_commande'])); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($detail['nom_produit']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo number_format($detail['prix_unitaire'], 2, ',', ' '); ?> €
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo $detail['quantite']; ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($detail['prix_total'], 2, ',', ' '); ?> €</strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($detail['nom_fournisseur'])): ?>
                                            <?php echo htmlspecialchars($detail['nom_fournisseur']); ?>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-style: italic;">Aucun</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <button class="btn btn-warning btn-sm" onclick="modifierDetail(<?php echo $detail['id_detail']; ?>)" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white; padding: 8px 16px; font-size: 12px;">
                                            <i class="fas fa-edit"></i> Modifier
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="supprimerDetail(<?php echo $detail['id_detail']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 8px 16px; font-size: 12px;">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals (conservés avec le style existant mais modernisés) -->
    <div id="modalAjouter" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5);">
        <div class="modal-content" style="background-color: white; margin: 2% auto; padding: 0; border-radius: 20px; width: 90%; max-width: 500px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);">
            <div class="modal-header" style="position: sticky; top: 0; background: white; z-index: 10; padding: 25px 30px 20px; border-bottom: 2px solid rgba(67, 97, 238, 0.1);">
                <h3 class="modal-title" style="color: var(--primary); font-size: 1.5rem; margin: 0; font-weight: 600;">Ajouter un Détail de Commande</h3>
                <button class="close" onclick="closeModal('modalAjouter')" style="position: absolute; right: 20px; top: 20px; color: var(--gray); font-size: 28px; font-weight: bold; cursor: pointer; background: none; border: none; z-index: 11;">&times;</button>
            </div>
            <form method="POST" style="padding: 0 30px 30px;">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Commande *</label>
                    <select name="commande_id" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                        <option value="">Sélectionnez une commande</option>
                        <?php foreach ($commandes as $commande): ?>
                            <option value="<?php echo $commande['id_commande']; ?>">
                                Commande #<?php echo $commande['id_commande']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Produit *</label>
                    <select name="produit_id" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                        <option value="">Sélectionnez un produit</option>
                        <?php foreach ($produits as $produit): ?>
                            <option value="<?php echo $produit['id_produit']; ?>">
                                <?php echo htmlspecialchars($produit['nom_produit']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Quantité *</label>
                    <input type="number" name="quantite" class="form-control" min="1" value="1" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px;">
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')"
                        style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" name="ajouter_detail" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-save"></i> Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Graphique d'évolution des commandes
        const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
        const evolutionChart = new Chart(evolutionCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'mois')); ?>,
                datasets: [{
                    label: 'Montant Total (€)',
                    data: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'montant_total')); ?>,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Quantité Totale',
                    data: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'quantite_totale')); ?>,
                    borderColor: '#f72585',
                    backgroundColor: 'rgba(247, 37, 133, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Montant (€)'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Quantité'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.yAxisID === 'y') {
                                    label += new Intl.NumberFormat('fr-FR', {
                                        style: 'currency',
                                        currency: 'EUR'
                                    }).format(context.parsed.y);
                                } else {
                                    label += context.parsed.y + ' unités';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Graphique des top produits
        const topProduitsCtx = document.getElementById('topProduitsChart').getContext('2d');
        const topProduitsChart = new Chart(topProduitsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($top_produits, 'nom_produit')); ?>,
                datasets: [{
                    label: 'Quantité Commandée',
                    data: <?php echo json_encode(array_column($top_produits, 'quantite_totale')); ?>,
                    backgroundColor: '#4cc9f0',
                    borderColor: '#4895ef',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Quantité'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });

        // Gestion des modals
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function modifierDetail(id) {
            window.location.href = 'details_commande.php?modifier=' + id;
        }

        function supprimerDetail(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce détail de commande ?')) {
                window.location.href = 'details_commande.php?supprimer=' + id;
            }
        }

        // Ouvrir le modal de modification si un détail est à modifier
        <?php if ($detail_a_modifier): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openModal('modalModifier');
            });
        <?php endif; ?>

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        // Auto-fermer les alertes après 5 secondes
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>