<?php
// Views/admin/mouvements.php
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

// Vérifier et créer les colonnes manquantes
$conn->query("
    ALTER TABLE produits 
    ADD COLUMN IF NOT EXISTS quantite_stock INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS seuil_alerte INT DEFAULT 10,
    ADD COLUMN IF NOT EXISTS date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
");

// Vérifier et créer la table mouvement_stock si elle n'existe pas
$conn->query("
    CREATE TABLE IF NOT EXISTS mouvement_stock (
        id_mouvement INT AUTO_INCREMENT PRIMARY KEY,
        produit_id INT NOT NULL,
        type_mouvement ENUM('entree', 'sortie', 'ajustement') NOT NULL,
        quantite INT NOT NULL,
        date_mouvement DATETIME DEFAULT CURRENT_TIMESTAMP,
        reference VARCHAR(100),
        motif TEXT,
        utilisateur_id INT,
        emplacement VARCHAR(100),
        prix_unitaire DECIMAL(10,2),
        fournisseur_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (produit_id) REFERENCES produits(id_produit),
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id_utilisateur),
        FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id_fournisseur)
    )
");

// Variables pour la recherche et le filtrage
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$produit_filter = $_GET['produit'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$sort_by = $_GET['sort'] ?? 'date_mouvement';
$sort_order = $_GET['order'] ?? 'desc';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(p.nom_produit LIKE ? OR ms.reference LIKE ? OR ms.motif LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if (!empty($type_filter)) {
    $where_conditions[] = "ms.type_mouvement = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($produit_filter)) {
    $where_conditions[] = "ms.produit_id = ?";
    $params[] = $produit_filter;
    $types .= 'i';
}

if (!empty($date_debut)) {
    $where_conditions[] = "DATE(ms.date_mouvement) >= ?";
    $params[] = $date_debut;
    $types .= 's';
}

if (!empty($date_fin)) {
    $where_conditions[] = "DATE(ms.date_mouvement) <= ?";
    $params[] = $date_fin;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Ordre de tri sécurisé
$allowed_sorts = ['id_mouvement', 'nom_produit', 'type_mouvement', 'quantite', 'date_mouvement', 'reference'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'date_mouvement';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des mouvements de stock avec filtres
$mouvements = [];
$sql = "SELECT ms.*, p.nom_produit, 
               COALESCE(p.quantite_stock, 0) as quantite_stock,
               COALESCE(p.seuil_alerte, 10) as seuil_alerte,
               u.nom as nom_utilisateur, f.nom as nom_fournisseur
        FROM mouvement_stock ms 
        JOIN produits p ON ms.produit_id = p.id_produit 
        LEFT JOIN utilisateurs u ON ms.utilisateur_id = u.id_utilisateur
        LEFT JOIN fournisseurs f ON ms.fournisseur_id = f.id_fournisseur
        $where_sql 
        ORDER BY $sort_by $sort_order";

try {
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $mouvements = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt_mouvements = $conn->query($sql);
        if ($stmt_mouvements) {
            $mouvements = $stmt_mouvements->fetch_all(MYSQLI_ASSOC);
        }
    }
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des mouvements: " . $e->getMessage();
}

// Récupération des produits pour le filtre
$produits = [];
$stmt_produits = $conn->query("SELECT id_produit, nom_produit, COALESCE(quantite_stock, 0) as quantite_stock FROM produits ORDER BY nom_produit");
if ($stmt_produits) {
    $produits = $stmt_produits->fetch_all(MYSQLI_ASSOC);
}

// Récupération des fournisseurs
$fournisseurs = [];
$stmt_fournisseurs = $conn->query("SELECT id_fournisseur, nom FROM fournisseurs ORDER BY nom");
if ($stmt_fournisseurs) {
    $fournisseurs = $stmt_fournisseurs->fetch_all(MYSQLI_ASSOC);
}

// Récupération des statistiques pour les graphiques
$stats_mouvements = [];
$sql_stats = "SELECT 
    COUNT(*) as total_mouvements,
    SUM(CASE WHEN type_mouvement = 'entree' THEN 1 ELSE 0 END) as entrees,
    SUM(CASE WHEN type_mouvement = 'sortie' THEN 1 ELSE 0 END) as sorties,
    SUM(CASE WHEN type_mouvement = 'ajustement' THEN 1 ELSE 0 END) as ajustements,
    SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as quantite_entree,
    SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as quantite_sortie,
    COUNT(DISTINCT produit_id) as produits_impactes
FROM mouvement_stock";

try {
    $result_stats = $conn->query($sql_stats);
    if ($result_stats) {
        $stats_mouvements = $result_stats->fetch_assoc();
    }
} catch (Exception $e) {
    $stats_mouvements = ['total_mouvements' => 0, 'entrees' => 0, 'sorties' => 0, 'ajustements' => 0];
}

// Statistiques par mois pour le graphique d'évolution
$stats_evolution = [];
$sql_evolution = "SELECT 
    DATE_FORMAT(date_mouvement, '%Y-%m') as mois,
    COUNT(*) as nb_mouvements,
    SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as quantite_entree,
    SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as quantite_sortie
FROM mouvement_stock
GROUP BY DATE_FORMAT(date_mouvement, '%Y-%m')
ORDER BY mois DESC
LIMIT 12";

try {
    $result_evolution = $conn->query($sql_evolution);
    if ($result_evolution) {
        $stats_evolution = $result_evolution->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $stats_evolution = [];
}

// Top 5 des produits avec le plus de mouvements
$top_produits = [];
$sql_top_produits = "SELECT 
    p.nom_produit,
    COUNT(ms.id_mouvement) as nb_mouvements,
    SUM(CASE WHEN ms.type_mouvement = 'entree' THEN ms.quantite ELSE 0 END) as entree_totale,
    SUM(CASE WHEN ms.type_mouvement = 'sortie' THEN ms.quantite ELSE 0 END) as sortie_totale
FROM mouvement_stock ms
JOIN produits p ON ms.produit_id = p.id_produit
GROUP BY p.id_produit, p.nom_produit
ORDER BY nb_mouvements DESC
LIMIT 5";

try {
    $result_top_produits = $conn->query($sql_top_produits);
    if ($result_top_produits) {
        $top_produits = $result_top_produits->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $top_produits = [];
}

// Gestion de l'ajout de mouvement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_mouvement'])) {
    $produit_id = intval($_POST['produit_id']);
    $type_mouvement = $_POST['type_mouvement'];
    $quantite = intval($_POST['quantite']);
    $reference = trim($_POST['reference']);
    $motif = trim($_POST['motif']);
    $emplacement = trim($_POST['emplacement']);
    $prix_unitaire = floatval($_POST['prix_unitaire']);
    $fournisseur_id = !empty($_POST['fournisseur_id']) ? intval($_POST['fournisseur_id']) : null;
    $utilisateur_id = $_SESSION['utilisateur']['id_utilisateur'];
    
    // Validation
    if ($quantite <= 0) {
        $error = "La quantité doit être supérieure à 0.";
    } else {
        // Pour les sorties, vérifier le stock disponible
        if ($type_mouvement === 'sortie') {
            $stmt_stock = $conn->prepare("SELECT COALESCE(quantite_stock, 0) as quantite_stock FROM produits WHERE id_produit = ?");
            $stmt_stock->bind_param("i", $produit_id);
            $stmt_stock->execute();
            $result_stock = $stmt_stock->get_result();
            $produit = $result_stock->fetch_assoc();
            
            if ($produit && $produit['quantite_stock'] < $quantite) {
                $error = "Stock insuffisant. Stock disponible : " . $produit['quantite_stock'];
            }
        }
        
        if (!isset($error)) {
            $stmt = $conn->prepare("INSERT INTO mouvement_stock (produit_id, type_mouvement, quantite, reference, motif, utilisateur_id, emplacement, prix_unitaire, fournisseur_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isissisdi", $produit_id, $type_mouvement, $quantite, $reference, $motif, $utilisateur_id, $emplacement, $prix_unitaire, $fournisseur_id);
            
            if ($stmt->execute()) {
                // Mettre à jour le stock du produit
                if ($type_mouvement === 'entree') {
                    $conn->query("UPDATE produits SET quantite_stock = COALESCE(quantite_stock, 0) + $quantite WHERE id_produit = $produit_id");
                } elseif ($type_mouvement === 'sortie') {
                    $conn->query("UPDATE produits SET quantite_stock = COALESCE(quantite_stock, 0) - $quantite WHERE id_produit = $produit_id");
                }
                
                $success = "Mouvement de stock enregistré avec succès!";
                header('Location: mouvements.php?success=1');
                exit();
            } else {
                $error = "Erreur lors de l'enregistrement : " . $conn->error;
            }
        }
    }
}

// Gestion de la suppression de mouvement
if (isset($_GET['supprimer'])) {
    $id_mouvement = $_GET['supprimer'];
    
    // Récupérer les informations du mouvement pour ajuster le stock
    $stmt_info = $conn->prepare("SELECT produit_id, type_mouvement, quantite FROM mouvement_stock WHERE id_mouvement = ?");
    $stmt_info->bind_param("i", $id_mouvement);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();
    $mouvement_info = $result_info->fetch_assoc();
    
    if ($mouvement_info) {
        // Ajuster le stock en sens inverse
        if ($mouvement_info['type_mouvement'] === 'entree') {
            $conn->query("UPDATE produits SET quantite_stock = COALESCE(quantite_stock, 0) - {$mouvement_info['quantite']} WHERE id_produit = {$mouvement_info['produit_id']}");
        } elseif ($mouvement_info['type_mouvement'] === 'sortie') {
            $conn->query("UPDATE produits SET quantite_stock = COALESCE(quantite_stock, 0) + {$mouvement_info['quantite']} WHERE id_produit = {$mouvement_info['produit_id']}");
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM mouvement_stock WHERE id_mouvement = ?");
    $stmt->bind_param("i", $id_mouvement);
    
    if ($stmt->execute()) {
        $success = "Mouvement supprimé avec succès!";
        header('Location: mouvements.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la suppression : " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Mouvements de Stock</title>
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

        .badge-entree {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

        .badge-sortie {
            background: linear-gradient(135deg, #f72585, #b5179e);
            color: white;
        }

        .badge-ajustement {
            background: linear-gradient(135deg, #7209b7, #560bad);
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
            <i class="fas fa-boxes fa-2x" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
            <h1>STOCKFLOW</h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo $_SESSION['utilisateur']['nom']; ?></h2>
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
            <a href="mouvements.php" class="nav-item active">
                <i class="fas fa-exchange-alt"></i>
                <span>Mouvements Stock</span>
            </a>
            <a href="fournisseurs.php" class="nav-item">
                <i class="fas fa-truck"></i>
                <span>Fournisseurs</span>
            </a>
            <a href="utilisateurs.php" class="nav-item">
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
                <h1>Mouvements de Stock</h1>
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
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['total_mouvements'] ?? 0; ?></div>
                    <div class="stat-label">Total Mouvements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['entrees'] ?? 0; ?></div>
                    <div class="stat-label">Entrées</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['sorties'] ?? 0; ?></div>
                    <div class="stat-label">Sorties</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_mouvements['ajustements'] ?? 0; ?></div>
                    <div class="stat-label">Ajustements</div>
                </div>
            </div>

            <!-- Graphiques de mouvement en temps réel -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Évolution des Mouvements (12 mois)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Top 5 Produits avec le Plus de Mouvements</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="topProduitsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- En-tête de page -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Historique des Mouvements</h2>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                        <i class="fas fa-plus"></i> Nouveau Mouvement
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
                        <input type="text" name="search" class="form-control" placeholder="Produit, référence ou motif..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="">Tous les types</option>
                            <option value="entree" <?php echo $type_filter == 'entree' ? 'selected' : ''; ?>>Entrée</option>
                            <option value="sortie" <?php echo $type_filter == 'sortie' ? 'selected' : ''; ?>>Sortie</option>
                            <option value="ajustement" <?php echo $type_filter == 'ajustement' ? 'selected' : ''; ?>>Ajustement</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Produit</label>
                        <select name="produit" class="form-select">
                            <option value="">Tous les produits</option>
                            <?php foreach ($produits as $produit): ?>
                                <option value="<?php echo $produit['id_produit']; ?>" <?php echo $produit_filter == $produit['id_produit'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($produit['nom_produit']); ?> (Stock: <?php echo $produit['quantite_stock']; ?>)
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
                        <label class="form-label">Trier par</label>
                        <select name="sort" class="form-select">
                            <option value="date_mouvement" <?php echo $sort_by === 'date_mouvement' ? 'selected' : ''; ?>>Date</option>
                            <option value="nom_produit" <?php echo $sort_by === 'nom_produit' ? 'selected' : ''; ?>>Produit</option>
                            <option value="type_mouvement" <?php echo $sort_by === 'type_mouvement' ? 'selected' : ''; ?>>Type</option>
                            <option value="quantite" <?php echo $sort_by === 'quantite' ? 'selected' : ''; ?>>Quantité</option>
                            <option value="reference" <?php echo $sort_by === 'reference' ? 'selected' : ''; ?>>Référence</option>
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
                        <a href="mouvements.php" class="btn" style="background: linear-gradient(135deg, #6c757d, #495057); color: white; margin-left: 10px;">
                            <i class="fas fa-times"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tableau des mouvements -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Produit</th>
                            <th>Type</th>
                            <th>Quantité</th>
                            <th>Stock Actuel</th>
                            <th>Référence</th>
                            <th>Motif</th>
                            <th>Prix Unitaire</th>
                            <th>Utilisateur</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($mouvements)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 30px; color: var(--gray);">Aucun mouvement de stock trouvé</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($mouvements as $mouvement): ?>
                            <tr>
                                <td><?php echo $mouvement['id_mouvement']; ?></td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($mouvement['date_mouvement'])); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($mouvement['nom_produit']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $mouvement['type_mouvement']; ?>">
                                        <?php 
                                        $types = [
                                            'entree' => 'Entrée',
                                            'sortie' => 'Sortie',
                                            'ajustement' => 'Ajustement'
                                        ];
                                        echo $types[$mouvement['type_mouvement']];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo $mouvement['quantite']; ?></strong>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: <?php echo $mouvement['quantite_stock'] <= $mouvement['seuil_alerte'] ? 'var(--danger)' : 'var(--success)'; ?>">
                                        <?php echo $mouvement['quantite_stock']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($mouvement['reference'])): ?>
                                        <?php echo htmlspecialchars($mouvement['reference']); ?>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($mouvement['motif'])): ?>
                                        <?php echo substr(htmlspecialchars($mouvement['motif']), 0, 50); ?>...
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Aucun</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($mouvement['prix_unitaire'])): ?>
                                        <?php echo number_format($mouvement['prix_unitaire'], 2, ',', ' '); ?> €
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($mouvement['nom_utilisateur'])): ?>
                                        <?php echo htmlspecialchars($mouvement['nom_utilisateur']); ?>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Système</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button class="btn btn-danger btn-sm" onclick="supprimerMouvement(<?php echo $mouvement['id_mouvement']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 8px 16px; font-size: 12px;">
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

    <!-- Modal Ajouter Mouvement -->
    <div id="modalAjouter" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5);">
        <div class="modal-content" style="background-color: white; margin: 2% auto; padding: 0; border-radius: 20px; width: 90%; max-width: 600px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);">
            <div class="modal-header" style="position: sticky; top: 0; background: white; z-index: 10; padding: 25px 30px 20px; border-bottom: 2px solid rgba(67, 97, 238, 0.1);">
                <h3 class="modal-title" style="color: var(--primary); font-size: 1.5rem; margin: 0; font-weight: 600;">Nouveau Mouvement de Stock</h3>
                <button class="close" onclick="closeModal('modalAjouter')" style="position: absolute; right: 20px; top: 20px; color: var(--gray); font-size: 28px; font-weight: bold; cursor: pointer; background: none; border: none; z-index: 11;">&times;</button>
            </div>
            <form method="POST" style="padding: 0 30px 30px;">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Produit *</label>
                    <select name="produit_id" id="produit_id" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                        <option value="">Sélectionnez un produit</option>
                        <?php foreach ($produits as $produit): ?>
                            <option value="<?php echo $produit['id_produit']; ?>" data-stock="<?php echo $produit['quantite_stock']; ?>">
                                <?php echo htmlspecialchars($produit['nom_produit']); ?> (Stock: <?php echo $produit['quantite_stock']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Type de mouvement *</label>
                    <select name="type_mouvement" id="type_mouvement" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                        <option value="entree">Entrée de stock</option>
                        <option value="sortie">Sortie de stock</option>
                        <option value="ajustement">Ajustement de stock</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Quantité *</label>
                    <input type="number" name="quantite" id="quantite" class="form-control" min="1" value="1" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px;">
                    <small id="stock_info" style="color: var(--gray); font-size: 12px; margin-top: 5px; display: block;"></small>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Référence</label>
                    <input type="text" name="reference" class="form-control" placeholder="N° bon, facture, etc." style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Motif</label>
                    <textarea name="motif" class="form-control" placeholder="Raison du mouvement..." rows="3" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; resize: vertical;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Emplacement</label>
                    <input type="text" name="emplacement" class="form-control" placeholder="Rayon, étagère, etc." style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Prix Unitaire (€)</label>
                    <input type="number" name="prix_unitaire" class="form-control" step="0.01" min="0" placeholder="0.00" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Fournisseur</label>
                    <select name="fournisseur_id" class="form-select" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 14px; background-color: white;">
                        <option value="">Aucun fournisseur</option>
                        <?php foreach ($fournisseurs as $fournisseur): ?>
                            <option value="<?php echo $fournisseur['id_fournisseur']; ?>">
                                <?php echo htmlspecialchars($fournisseur['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" 
                            style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" name="ajouter_mouvement" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Graphique d'évolution des mouvements
        const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
        const evolutionChart = new Chart(evolutionCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'mois')); ?>,
                datasets: [{
                    label: 'Entrées',
                    data: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'quantite_entree')); ?>,
                    borderColor: '#4cc9f0',
                    backgroundColor: 'rgba(76, 201, 240, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Sorties',
                    data: <?php echo json_encode(array_column(array_reverse($stats_evolution), 'quantite_sortie')); ?>,
                    borderColor: '#f72585',
                    backgroundColor: 'rgba(247, 37, 133, 0.1)',
                    tension: 0.4,
                    fill: true
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
                        position: 'top',
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
                    label: 'Nombre de mouvements',
                    data: <?php echo json_encode(array_column($top_produits, 'nb_mouvements')); ?>,
                    backgroundColor: '#4361ee',
                    borderColor: '#3a56d4',
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
                            text: 'Nombre de mouvements'
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

        function supprimerMouvement(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce mouvement ? Cette action est irréversible.')) {
                window.location.href = 'mouvements.php?supprimer=' + id;
            }
        }

        // Gestion dynamique du stock
        document.addEventListener('DOMContentLoaded', function() {
            const produitSelect = document.getElementById('produit_id');
            const typeSelect = document.getElementById('type_mouvement');
            const quantiteInput = document.getElementById('quantite');
            const stockInfo = document.getElementById('stock_info');

            function updateStockInfo() {
                const selectedOption = produitSelect.options[produitSelect.selectedIndex];
                const stock = selectedOption ? selectedOption.getAttribute('data-stock') : 0;
                const type = typeSelect.value;
                const quantite = parseInt(quantiteInput.value) || 0;

                if (stock && type === 'sortie') {
                    const nouveauStock = stock - quantite;
                    stockInfo.innerHTML = `Stock actuel: ${stock} | Après mouvement: ${nouveauStock}`;
                    stockInfo.style.color = nouveauStock < 0 ? 'var(--danger)' : 'var(--success)';
                } else if (stock) {
                    stockInfo.innerHTML = `Stock actuel: ${stock}`;
                    stockInfo.style.color = 'var(--gray)';
                } else {
                    stockInfo.innerHTML = '';
                }
            }

            if (produitSelect) produitSelect.addEventListener('change', updateStockInfo);
            if (typeSelect) typeSelect.addEventListener('change', updateStockInfo);
            if (quantiteInput) quantiteInput.addEventListener('input', updateStockInfo);
            
            // Initialiser l'affichage
            updateStockInfo();
        });

        // Auto-fermer les alertes après 5 secondes
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }
    </script>
</body>
</html>