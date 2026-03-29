<?php
// Views/admin/fournisseurs.php
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
$sort_by = $_GET['sort'] ?? 'nom';
$sort_order = $_GET['order'] ?? 'asc';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(nom LIKE ? OR contact LIKE ? OR adresse LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Ordre de tri sécurisé
$allowed_sorts = ['nom', 'contact', 'id_fournisseur'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'nom';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des fournisseurs avec filtres
$fournisseurs = [];
$sql = "SELECT * FROM fournisseurs $where_sql ORDER BY $sort_by $sort_order";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $fournisseurs = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt_fournisseurs = $conn->query($sql);
    if ($stmt_fournisseurs) {
        $fournisseurs = $stmt_fournisseurs->fetch_all(MYSQLI_ASSOC);
    }
}

// Récupération des statistiques pour les graphiques
$stats_fournisseurs = [];
$sql_stats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN contact IS NOT NULL AND contact != '' THEN 1 ELSE 0 END) as avec_contact,
    SUM(CASE WHEN adresse IS NOT NULL AND adresse != '' THEN 1 ELSE 0 END) as avec_adresse
FROM fournisseurs";

$result_stats = $conn->query($sql_stats);
if ($result_stats) {
    $stats_fournisseurs = $result_stats->fetch_assoc();
}

// Gestion de l'ajout de fournisseur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_fournisseur'])) {
    $nom = trim($_POST['nom']);
    $contact = trim($_POST['contact']);
    $adresse = trim($_POST['adresse']);

    // Vérifier si le fournisseur existe déjà
    $stmt_check = $conn->prepare("SELECT id_fournisseur FROM fournisseurs WHERE nom = ?");
    $stmt_check->bind_param("s", $nom);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $error = "Un fournisseur avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("INSERT INTO fournisseurs (nom, contact, adresse) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $nom, $contact, $adresse);
        
        if ($stmt->execute()) {
            $success = "Fournisseur ajouté avec succès!";
            header('Location: fournisseurs.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de l'ajout du fournisseur : " . $conn->error;
        }
    }
}

// Gestion de la modification de fournisseur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_fournisseur'])) {
    $id_fournisseur = $_POST['id_fournisseur'];
    $nom = trim($_POST['nom']);
    $contact = trim($_POST['contact']);
    $adresse = trim($_POST['adresse']);

    // Vérifier si le nom existe déjà pour un autre fournisseur
    $stmt_check = $conn->prepare("SELECT id_fournisseur FROM fournisseurs WHERE nom = ? AND id_fournisseur != ?");
    $stmt_check->bind_param("si", $nom, $id_fournisseur);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $error = "Un autre fournisseur avec ce nom existe déjà.";
    } else {
        $stmt = $conn->prepare("UPDATE fournisseurs SET nom = ?, contact = ?, adresse = ? WHERE id_fournisseur = ?");
        $stmt->bind_param("sssi", $nom, $contact, $adresse, $id_fournisseur);
        
        if ($stmt->execute()) {
            $success = "Fournisseur modifié avec succès!";
            header('Location: fournisseurs.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de la modification du fournisseur : " . $conn->error;
        }
    }
}

// Gestion de la suppression de fournisseur
if (isset($_GET['supprimer'])) {
    $id_fournisseur = $_GET['supprimer'];
    
    $stmt = $conn->prepare("DELETE FROM fournisseurs WHERE id_fournisseur = ?");
    $stmt->bind_param("i", $id_fournisseur);
    
    if ($stmt->execute()) {
        $success = "Fournisseur supprimé avec succès!";
        header('Location: fournisseurs.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la suppression du fournisseur : " . $conn->error;
    }
}

// Récupérer un fournisseur pour modification
$fournisseur_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_fournisseur = $_GET['modifier'];
    $stmt = $conn->prepare("SELECT * FROM fournisseurs WHERE id_fournisseur = ?");
    $stmt->bind_param("i", $id_fournisseur);
    $stmt->execute();
    $result = $stmt->get_result();
    $fournisseur_a_modifier = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Gestion des Fournisseurs</title>
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

        /* Styles modernisés pour les composants existants */
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
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
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

        /* Nouveaux styles pour les graphiques */
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

        /* Animation pour les cartes */
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

        .stat-card, .chart-card {
            animation: fadeInUp 0.6s ease-out;
        }

        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .chart-card:nth-child(2) { animation-delay: 0.3s; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .logo h1, .welcome, .nav-item span {
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
        }

        /* Reste des styles existants conservés mais modernisés */
        .filters-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

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
                <h1>Gestion des Fournisseurs</h1>
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
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_fournisseurs['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Fournisseurs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_fournisseurs['avec_contact'] ?? 0; ?></div>
                    <div class="stat-label">Avec Contact</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_fournisseurs['avec_adresse'] ?? 0; ?></div>
                    <div class="stat-label">Avec Adresse</div>
                </div>
            </div>

            <!-- Graphiques -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Répartition des Fournisseurs</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="fournisseursChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Évolution des Fournisseurs</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- En-tête de page -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Liste des Fournisseurs</h2>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                        <i class="fas fa-plus"></i> Nouveau Fournisseur
                    </button>
                    <a href="?export=1" class="btn" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white;">
                        <i class="fas fa-download"></i> Exporter CSV
                    </a>
                </div>
            </div>

            <!-- Filtres et recherche -->
            <div class="filters-container">
                <form method="GET" class="filters-form" style="display: grid; grid-template-columns: 1fr auto auto; gap: 15px; align-items: end;">
                    <div class="form-group">
                        <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark); font-size: 14px;">Rechercher</label>
                        <input type="text" name="search" class="form-control" placeholder="Nom, contact ou adresse..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; transition: border-color 0.3s;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark); font-size: 14px;">Trier par</label>
                        <select name="sort" class="form-select" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                            <option value="nom" <?php echo $sort_by === 'nom' ? 'selected' : ''; ?>>Nom</option>
                            <option value="contact" <?php echo $sort_by === 'contact' ? 'selected' : ''; ?>>Contact</option>
                            <option value="id_fournisseur" <?php echo $sort_by === 'id_fournisseur' ? 'selected' : ''; ?>>ID</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark); font-size: 14px;">Ordre</label>
                        <select name="order" class="form-select" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                            <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Croissant</option>
                            <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Décroissant</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filtrer
                        </button>
                        <a href="fournisseurs.php" class="btn" style="background: linear-gradient(135deg, #6c757d, #495057); color: white; margin-left: 10px;">
                            <i class="fas fa-times"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tableau des fournisseurs -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom du Fournisseur</th>
                            <th>Contact</th>
                            <th>Adresse</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fournisseurs)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 30px; color: var(--gray);">Aucun fournisseur trouvé</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fournisseurs as $fournisseur): ?>
                            <tr>
                                <td><?php echo $fournisseur['id_fournisseur']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fournisseur['nom']); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($fournisseur['contact'])): ?>
                                        <?php echo htmlspecialchars($fournisseur['contact']); ?>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Non renseigné</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($fournisseur['adresse'])): ?>
                                        <?php echo substr(htmlspecialchars($fournisseur['adresse']), 0, 50); ?>...
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Non renseignée</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button class="btn btn-warning btn-sm" onclick="modifierFournisseur(<?php echo $fournisseur['id_fournisseur']; ?>)" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white; padding: 8px 16px; font-size: 12px;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="supprimerFournisseur(<?php echo $fournisseur['id_fournisseur']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 8px 16px; font-size: 12px;">
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

    <!-- Modals (conservés avec le style existant) -->
    <div id="modalAjouter" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5);">
        <div class="modal-content" style="background-color: white; margin: 2% auto; padding: 0; border-radius: 15px; width: 90%; max-width: 500px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);">
            <div class="modal-header" style="position: sticky; top: 0; background: white; z-index: 10; padding: 20px 30px 15px; border-bottom: 2px solid var(--light);">
                <h3 class="modal-title" style="color: var(--primary); font-size: 18px; margin: 0; font-weight: 600;">Ajouter un Fournisseur</h3>
                <button class="close" onclick="closeModal('modalAjouter')" style="position: absolute; right: 15px; top: 15px; color: var(--gray); font-size: 24px; font-weight: bold; cursor: pointer; background: none; border: none; z-index: 11;">&times;</button>
            </div>
            <form method="POST" style="padding: 0 30px 30px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Nom du fournisseur *</label>
                    <input type="text" name="nom" class="form-control" required maxlength="100" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Contact</label>
                    <input type="text" name="contact" class="form-control" placeholder="Email ou téléphone..." maxlength="100" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Adresse</label>
                    <textarea name="adresse" class="form-control form-textarea" placeholder="Adresse complète du fournisseur..." maxlength="500" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; min-height: 80px; resize: vertical;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 10px 20px;">Annuler</button>
                    <button type="submit" name="ajouter_fournisseur" class="btn btn-success" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 10px 20px;">Ajouter le fournisseur</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Graphique circulaire pour la répartition
        const fournisseursCtx = document.getElementById('fournisseursChart').getContext('2d');
        const fournisseursChart = new Chart(fournisseursCtx, {
            type: 'doughnut',
            data: {
                labels: ['Avec Contact', 'Sans Contact', 'Avec Adresse', 'Sans Adresse'],
                datasets: [{
                    data: [
                        <?php echo $stats_fournisseurs['avec_contact'] ?? 0; ?>,
                        <?php echo ($stats_fournisseurs['total'] ?? 0) - ($stats_fournisseurs['avec_contact'] ?? 0); ?>,
                        <?php echo $stats_fournisseurs['avec_adresse'] ?? 0; ?>,
                        <?php echo ($stats_fournisseurs['total'] ?? 0) - ($stats_fournisseurs['avec_adresse'] ?? 0); ?>
                    ],
                    backgroundColor: [
                        '#4361ee',
                        '#7209b7',
                        '#4cc9f0',
                        '#f72585'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                },
                cutout: '60%'
            }
        });

        // Graphique d'évolution (exemple avec données fictives)
        const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
        const evolutionChart = new Chart(evolutionCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Nouveaux Fournisseurs',
                    data: [2, 4, 3, 5, 4, 6],
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
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

        function modifierFournisseur(id) {
            window.location.href = 'fournisseurs.php?modifier=' + id;
        }

        function supprimerFournisseur(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce fournisseur ?')) {
                window.location.href = 'fournisseurs.php?supprimer=' + id;
            }
        }

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        // Ouvrir le modal de modification si nécessaire
        <?php if ($fournisseur_a_modifier): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openModal('modalModifier');
            });
        <?php endif; ?>
    </script>
</body>
</html>