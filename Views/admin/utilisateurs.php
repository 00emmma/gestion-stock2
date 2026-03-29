<?php
// Views/admin/utilisateurs.php
session_start();
require_once __DIR__ . '/../../config/config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Récupération des utilisateurs
try {
    $stmt = $pdo->query("SELECT * FROM utilisateurs ORDER BY id_utilisateur DESC");
    $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur lors de la récupération des utilisateurs : " . $e->getMessage());
}

// Récupération des statistiques pour les graphiques
try {
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as users,
        SUM(CASE WHEN telephone IS NOT NULL AND telephone != '' THEN 1 ELSE 0 END) as avec_telephone
    FROM utilisateurs";
    $stmt_stats = $pdo->query($stats_sql);
    $stats_utilisateurs = $stmt_stats->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stats_utilisateurs = ['total' => 0, 'admins' => 0, 'users' => 0, 'avec_telephone' => 0];
}

// Gestion de l'ajout d'utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_utilisateur'])) {
    $nom = $_POST['nom'];
    $email = $_POST['email'];
    $telephone = $_POST['telephone'];
    $role = $_POST['role'];
    $mot_de_passe = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, telephone, role, mot_de_passe) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nom, $email, $telephone, $role, $mot_de_passe]);
        $success = "Utilisateur ajouté avec succès!";
        header('Location: utilisateurs.php?success=1');
        exit();
    } catch(PDOException $e) {
        $error = "Erreur lors de l'ajout de l'utilisateur : " . $e->getMessage();
    }
}

// Gestion de la modification d'utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_utilisateur'])) {
    $id_utilisateur = $_POST['id_utilisateur'];
    $nom = $_POST['nom'];
    $email = $_POST['email'];
    $telephone = $_POST['telephone'];
    $role = $_POST['role'];

    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, email = ?, telephone = ?, role = ? WHERE id_utilisateur = ?");
        $stmt->execute([$nom, $email, $telephone, $role, $id_utilisateur]);
        $success = "Utilisateur modifié avec succès!";
        header('Location: utilisateurs.php?success=1');
        exit();
    } catch(PDOException $e) {
        $error = "Erreur lors de la modification de l'utilisateur : " . $e->getMessage();
    }
}

// Gestion de la suppression d'utilisateur
if (isset($_GET['supprimer'])) {
    $id_utilisateur = $_GET['supprimer'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->execute([$id_utilisateur]);
        $success = "Utilisateur supprimé avec succès!";
        header('Location: utilisateurs.php?success=1');
        exit();
    } catch(PDOException $e) {
        $error = "Erreur lors de la suppression de l'utilisateur : " . $e->getMessage();
    }
}

// Récupérer un utilisateur pour modification
$utilisateur_a_modifier = null;
if (isset($_GET['modifier'])) {
    $id_utilisateur = $_GET['modifier'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->execute([$id_utilisateur]);
        $utilisateur_a_modifier = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "Erreur lors de la récupération de l'utilisateur : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Gestion des Utilisateurs</title>
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

        .badge-success {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
        }

        .badge-warning {
            background: linear-gradient(135deg, var(--warning), #b5179e);
            color: white;
        }

        .badge-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
        }

        /* Actions */
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
            border-radius: 8px;
        }

        /* Modals modernisés */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .modal-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            padding: 25px 30px 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .modal-title {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
            font-weight: 600;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            color: var(--gray);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            z-index: 11;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 20px;
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

        /* Alertes modernisées */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            border: none;
        }

        .alert-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
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

        .stat-card, .chart-card, .table-container {
            animation: fadeInUp 0.6s ease-out;
        }

        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.3s; }

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

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-boxes fa-2x" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
            <h1>STOCKFLOW</h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, Administrateur</h2>
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
            <a href="produits.php" class="nav-item">
                <i class="fas fa-box"></i>
                <span>Produits</span>
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
                <i class="fas fa-truck"></i>
                <span>Fournisseurs</span>
            </a>
            <a href="utilisateurs.php" class="nav-item active">
                <i class="fas fa-users"></i>
                <span>Utilisateurs</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <button class="logout-btn" onclick="logout()" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; border: none; border-radius: 12px; padding: 15px; margin: 0 20px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; width: calc(100% - 40px); font-weight: 600;">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>Gestion des Utilisateurs</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 600; color: var(--primary);">Administrateur</p>
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

            <!-- Statistiques -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_utilisateurs['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Utilisateurs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_utilisateurs['admins'] ?? 0; ?></div>
                    <div class="stat-label">Administrateurs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_utilisateurs['users'] ?? 0; ?></div>
                    <div class="stat-label">Utilisateurs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_utilisateurs['avec_telephone'] ?? 0; ?></div>
                    <div class="stat-label">Avec Téléphone</div>
                </div>
            </div>

            <!-- Graphiques -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Répartition des Rôles</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="rolesChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Utilisateurs avec Téléphone</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="telephoneChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- En-tête de page -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Liste des Utilisateurs</h2>
                <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                    <i class="fas fa-plus"></i> Nouvel Utilisateur
                </button>
            </div>

            <!-- Tableau des utilisateurs -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>Email</th>
                            <th>Téléphone</th>
                            <th>Rôle</th>
                            <th>Date Création</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($utilisateurs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: var(--gray);">Aucun utilisateur trouvé</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($utilisateurs as $utilisateur): ?>
                            <tr>
                                <td><?php echo $utilisateur['id_utilisateur']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($utilisateur['nom']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($utilisateur['email']); ?></td>
                                <td>
                                    <?php if (!empty($utilisateur['telephone'])): ?>
                                        <?php echo htmlspecialchars($utilisateur['telephone']); ?>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Non renseigné</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $role = $utilisateur['role'] ?? 'user';
                                    $badge_class = $role === 'admin' ? 'badge-success' : 'badge-warning';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($role); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($utilisateur['date_creation'])); ?></td>
                                <td class="actions">
                                    <button class="btn btn-warning btn-sm" onclick="modifierUtilisateur(<?php echo $utilisateur['id_utilisateur']; ?>)" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="supprimerUtilisateur(<?php echo $utilisateur['id_utilisateur']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white;">
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

    <!-- Modal Ajouter Utilisateur -->
    <div id="modalAjouter" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Ajouter un Utilisateur</h3>
                <button class="close" onclick="closeModal('modalAjouter')">&times;</button>
            </div>
            <form method="POST" style="padding: 0 30px 30px;">
                <div class="form-group">
                    <label class="form-label">Nom complet *</label>
                    <input type="text" name="nom" class="form-control" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" maxlength="20">
                </div>
                <div class="form-group">
                    <label class="form-label">Rôle *</label>
                    <select name="role" class="form-select" required>
                        <option value="user">Utilisateur</option>
                        <option value="admin">Administrateur</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mot de passe *</label>
                    <input type="password" name="mot_de_passe" class="form-control" required minlength="6">
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" 
                            style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" name="ajouter_utilisateur" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-save"></i> Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier Utilisateur -->
    <div id="modalModifier" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Modifier l'Utilisateur</h3>
                <button class="close" onclick="closeModal('modalModifier')">&times;</button>
            </div>
            <?php if ($utilisateur_a_modifier): ?>
            <form method="POST" style="padding: 0 30px 30px;">
                <input type="hidden" name="id_utilisateur" value="<?php echo $utilisateur_a_modifier['id_utilisateur']; ?>">
                <div class="form-group">
                    <label class="form-label">Nom complet *</label>
                    <input type="text" name="nom" class="form-control" value="<?php echo htmlspecialchars($utilisateur_a_modifier['nom']); ?>" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($utilisateur_a_modifier['email']); ?>" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" value="<?php echo htmlspecialchars($utilisateur_a_modifier['telephone'] ?? ''); ?>" maxlength="20">
                </div>
                <div class="form-group">
                    <label class="form-label">Rôle *</label>
                    <select name="role" class="form-select" required>
                        <option value="user" <?php echo ($utilisateur_a_modifier['role'] ?? 'user') === 'user' ? 'selected' : ''; ?>>Utilisateur</option>
                        <option value="admin" <?php echo ($utilisateur_a_modifier['role'] ?? 'user') === 'admin' ? 'selected' : ''; ?>>Administrateur</option>
                    </select>
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalModifier')" 
                            style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" name="modifier_utilisateur" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-save"></i> Modifier
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Graphique des rôles
        const rolesCtx = document.getElementById('rolesChart').getContext('2d');
        const rolesChart = new Chart(rolesCtx, {
            type: 'doughnut',
            data: {
                labels: ['Administrateurs', 'Utilisateurs'],
                datasets: [{
                    data: [
                        <?php echo $stats_utilisateurs['admins'] ?? 0; ?>,
                        <?php echo $stats_utilisateurs['users'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#4361ee',
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

        // Graphique téléphone
        const telephoneCtx = document.getElementById('telephoneChart').getContext('2d');
        const telephoneChart = new Chart(telephoneCtx, {
            type: 'pie',
            data: {
                labels: ['Avec Téléphone', 'Sans Téléphone'],
                datasets: [{
                    data: [
                        <?php echo $stats_utilisateurs['avec_telephone'] ?? 0; ?>,
                        <?php echo ($stats_utilisateurs['total'] ?? 0) - ($stats_utilisateurs['avec_telephone'] ?? 0); ?>
                    ],
                    backgroundColor: [
                        '#4cc9f0',
                        '#e63946'
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

        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Fonctions pour les actions
        function modifierUtilisateur(id) {
            window.location.href = 'utilisateurs.php?modifier=' + id;
        }

        function supprimerUtilisateur(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')) {
                window.location.href = 'utilisateurs.php?supprimer=' + id;
            }
        }

        // Ouvrir le modal de modification si un utilisateur est à modifier
        <?php if ($utilisateur_a_modifier): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openModal('modalModifier');
            });
        <?php endif; ?>

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = 'logout.php';
            }
        }

        function openSettings() {
            window.location.href = 'parametres.php';
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