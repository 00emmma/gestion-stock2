<?php
// Views/admin/rapports.php
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

// Vérifier et créer les tables nécessaires
$conn->query("
    CREATE TABLE IF NOT EXISTS rapports (
        id_rapport INT AUTO_INCREMENT PRIMARY KEY,
        titre VARCHAR(255) NOT NULL,
        type_rapport ENUM('stock', 'ventes', 'mouvements', 'performance', 'personnalise') NOT NULL,
        description TEXT,
        periode_debut DATE,
        periode_fin DATE,
        parametres JSON,
        fichier_generer VARCHAR(255),
        statut ENUM('en_cours', 'termine', 'erreur') DEFAULT 'en_cours',
        utilisateur_id INT NOT NULL,
        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
        date_generation DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id_utilisateur)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS modeles_rapports (
        id_modele INT AUTO_INCREMENT PRIMARY KEY,
        nom_modele VARCHAR(255) NOT NULL,
        type_rapport ENUM('stock', 'ventes', 'mouvements', 'performance') NOT NULL,
        parametres JSON,
        est_actif BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Insérer les modèles par défaut s'ils n'existent pas
$modeles_existants = $conn->query("SELECT COUNT(*) as count FROM modeles_rapports")->fetch_assoc();
if ($modeles_existants['count'] == 0) {
    $modeles_defaut = [
        ['Rapport Stock Actuel', 'stock', '{"colonnes": ["nom_produit", "quantite_stock", "seuil_alerte", "valeur_stock"], "filtres": {"seuil_alerte": true}}'],
        ['Mouvements Mensuels', 'mouvements', '{"periode": "mois_courant", "group_by": "produit", "colonnes": ["produit", "entrees", "sorties", "solde"]}'],
        ['Top Produits Vendus', 'ventes', '{"periode": "30_jours", "limit": 10, "order_by": "quantite_vendue"}'],
        ['Performance Fournisseurs', 'performance', '{"periode": "3_mois", "metriques": ["delai_livraison", "qualite", "prix"]}']
    ];
    
    $stmt = $conn->prepare("INSERT INTO modeles_rapports (nom_modele, type_rapport, parametres) VALUES (?, ?, ?)");
    foreach ($modeles_defaut as $modele) {
        $stmt->bind_param("sss", $modele[0], $modele[1], $modele[2]);
        $stmt->execute();
    }
}

// Variables pour la recherche et le filtrage
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$sort_by = $_GET['sort'] ?? 'date_creation';
$sort_order = $_GET['order'] ?? 'desc';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(r.titre LIKE ? OR r.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if (!empty($type_filter)) {
    $where_conditions[] = "r.type_rapport = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($statut_filter)) {
    $where_conditions[] = "r.statut = ?";
    $params[] = $statut_filter;
    $types .= 's';
}

if (!empty($date_debut)) {
    $where_conditions[] = "DATE(r.date_creation) >= ?";
    $params[] = $date_debut;
    $types .= 's';
}

if (!empty($date_fin)) {
    $where_conditions[] = "DATE(r.date_creation) <= ?";
    $params[] = $date_fin;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Ordre de tri sécurisé
$allowed_sorts = ['id_rapport', 'titre', 'type_rapport', 'statut', 'date_creation', 'date_generation'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'date_creation';
$sort_order = $sort_order === 'desc' ? 'desc' : 'asc';

// Récupération des rapports avec filtres
$rapports = [];
$sql = "SELECT r.*, u.nom as nom_utilisateur
        FROM rapports r 
        JOIN utilisateurs u ON r.utilisateur_id = u.id_utilisateur
        $where_sql 
        ORDER BY $sort_by $sort_order";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rapports = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt_rapports = $conn->query($sql);
    if ($stmt_rapports) {
        $rapports = $stmt_rapports->fetch_all(MYSQLI_ASSOC);
    }
}

// Récupération des modèles de rapports
$modeles = [];
$stmt_modeles = $conn->query("SELECT * FROM modeles_rapports WHERE est_actif = TRUE ORDER BY nom_modele");
if ($stmt_modeles) {
    $modeles = $stmt_modeles->fetch_all(MYSQLI_ASSOC);
}

// Récupération des statistiques pour les graphiques
$stats_rapports = [];
$sql_stats = "SELECT 
    COUNT(*) as total_rapports,
    SUM(CASE WHEN statut = 'termine' THEN 1 ELSE 0 END) as rapports_termines,
    SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as rapports_en_cours,
    SUM(CASE WHEN statut = 'erreur' THEN 1 ELSE 0 END) as rapports_erreur,
    COUNT(DISTINCT type_rapport) as types_differents
FROM rapports";

$result_stats = $conn->query($sql_stats);
if ($result_stats) {
    $stats_rapports = $result_stats->fetch_assoc();
}

// Statistiques par type de rapport
$stats_types = [];
$sql_types = "SELECT 
    type_rapport,
    COUNT(*) as nombre,
    AVG(TIMESTAMPDIFF(MINUTE, date_creation, date_generation)) as temps_moyen
FROM rapports 
WHERE statut = 'termine' 
GROUP BY type_rapport
ORDER BY nombre DESC";

$result_types = $conn->query($sql_types);
if ($result_types) {
    $stats_types = $result_types->fetch_all(MYSQLI_ASSOC);
}

// Gestion de la génération de rapport
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generer_rapport'])) {
        $titre = trim($_POST['titre']);
        $type_rapport = $_POST['type_rapport'];
        $description = trim($_POST['description']);
        $periode_debut = $_POST['periode_debut'] ?: null;
        $periode_fin = $_POST['periode_fin'] ?: null;
        $utilisateur_id = $_SESSION['utilisateur']['id_utilisateur'];
        
        // Paramètres selon le type de rapport
        $parametres = [];
        
        switch ($type_rapport) {
            case 'stock':
                $parametres = [
                    'colonnes' => $_POST['colonnes_stock'] ?? ['nom_produit', 'quantite_stock', 'seuil_alerte'],
                    'filtres' => [
                        'seuil_alerte' => isset($_POST['seuil_alerte']),
                        'stock_zero' => isset($_POST['stock_zero'])
                    ]
                ];
                break;
                
            case 'mouvements':
                $parametres = [
                    'group_by' => $_POST['group_by_mouvements'] ?? 'produit',
                    'colonnes' => $_POST['colonnes_mouvements'] ?? ['produit', 'entrees', 'sorties', 'solde'],
                    'ordre' => $_POST['ordre_mouvements'] ?? 'quantite_desc'
                ];
                break;
                
            case 'ventes':
                $parametres = [
                    'limit' => intval($_POST['limit_ventes'] ?? 10),
                    'order_by' => $_POST['order_by_ventes'] ?? 'quantite_desc',
                    'include_details' => isset($_POST['include_details_ventes'])
                ];
                break;
                
            case 'performance':
                $parametres = [
                    'metriques' => $_POST['metriques_performance'] ?? ['delai_livraison', 'qualite'],
                    'periode_comparaison' => $_POST['periode_comparaison'] ?? 'mois_precedent'
                ];
                break;
        }
        
        $parametres_json = json_encode($parametres);
        
        $stmt = $conn->prepare("INSERT INTO rapports (titre, type_rapport, description, periode_debut, periode_fin, parametres, utilisateur_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $titre, $type_rapport, $description, $periode_debut, $periode_fin, $parametres_json, $utilisateur_id);
        
        if ($stmt->execute()) {
            $rapport_id = $conn->insert_id;
            
            // Simuler la génération du rapport (dans un vrai système, vous auriez un worker en arrière-plan)
            sleep(2);
            
            // Mettre à jour le statut du rapport
            $fichier_nom = "rapport_" . $rapport_id . "_" . date('Y-m-d_H-i-s') . ".pdf";
            $conn->query("UPDATE rapports SET statut = 'termine', fichier_generer = '$fichier_nom', date_generation = NOW() WHERE id_rapport = $rapport_id");
            
            $success = "Rapport généré avec succès!";
            header('Location: rapports.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de la génération du rapport : " . $conn->error;
        }
    }
    
    // Gestion de l'utilisation d'un modèle
    if (isset($_POST['utiliser_modele'])) {
        $modele_id = intval($_POST['modele_id']);
        
        $stmt_modele = $conn->prepare("SELECT * FROM modeles_rapports WHERE id_modele = ?");
        $stmt_modele->bind_param("i", $modele_id);
        $stmt_modele->execute();
        $result_modele = $stmt_modele->get_result();
        $modele = $result_modele->fetch_assoc();
        
        if ($modele) {
            $_SESSION['modele_rapport'] = $modele;
            header('Location: rapports.php?modele=' . $modele_id);
            exit();
        }
    }
}

// Gestion de la suppression de rapport
if (isset($_GET['supprimer'])) {
    $id_rapport = $_GET['supprimer'];
    
    $stmt = $conn->prepare("DELETE FROM rapports WHERE id_rapport = ?");
    $stmt->bind_param("i", $id_rapport);
    
    if ($stmt->execute()) {
        $success = "Rapport supprimé avec succès!";
        header('Location: rapports.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la suppression du rapport : " . $conn->error;
    }
}

// Gestion du téléchargement
if (isset($_GET['telecharger'])) {
    $id_rapport = $_GET['telecharger'];
    
    $stmt = $conn->prepare("SELECT fichier_generer FROM rapports WHERE id_rapport = ? AND statut = 'termine'");
    $stmt->bind_param("i", $id_rapport);
    $stmt->execute();
    $result = $stmt->get_result();
    $rapport = $result->fetch_assoc();
    
    if ($rapport && !empty($rapport['fichier_generer'])) {
        // Simuler le téléchargement (dans un vrai système, vous serviriez le fichier)
        $success = "Téléchargement du rapport initié";
    } else {
        $error = "Rapport non disponible au téléchargement";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Rapports et Analytics</title>
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

        .badge-en_cours {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

        .badge-termine {
            background: linear-gradient(135deg, #4ade80, #22c55e);
            color: white;
        }

        .badge-erreur {
            background: linear-gradient(135deg, #f72585, #b5179e);
            color: white;
        }

        /* Cartes de modèles */
        .modeles-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .modele-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(67, 97, 238, 0.1);
            transition: transform 0.3s ease;
        }

        .modele-card:hover {
            transform: translateY(-5px);
        }

        .modele-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .modele-icon {
            font-size: 24px;
            color: var(--primary);
        }

        .modele-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }

        .modele-type {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .modele-description {
            color: var(--gray);
            margin-bottom: 15px;
            font-size: 0.9rem;
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

        .stat-card, .chart-card, .table-container, .modele-card {
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

            .modeles-container {
                grid-template-columns: 1fr;
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
            <i class="fas fa-warehouse fa-2x" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
            <h1>GESTION<span>stock</span></h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo $_SESSION['utilisateur']['nom']; ?></h2>
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
            <a href="produits.php" class="nav-item">
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
                <span>Fournisseurs</span>
            </a>
            <a href="rapports.php" class="nav-item active">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="utilisateurs.php" class="nav-item">
                <i class="fas fa-users-cog"></i>
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
                <h1>Rapports et Analytics</h1>
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
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_rapports['total_rapports'] ?? 0; ?></div>
                    <div class="stat-label">Total Rapports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_rapports['rapports_termines'] ?? 0; ?></div>
                    <div class="stat-label">Rapports Terminés</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_rapports['rapports_en_cours'] ?? 0; ?></div>
                    <div class="stat-label">En Cours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shapes"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats_rapports['types_differents'] ?? 0; ?></div>
                    <div class="stat-label">Types Différents</div>
                </div>
            </div>

            <!-- Graphiques -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Répartition par Type de Rapport</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="typesChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Performance des Rapports</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Modèles prédéfinis -->
            <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: var(--primary); font-size: 1.5rem; font-weight: 600;">Modèles de Rapports Prédéfinis</h2>
            </div>

            <div class="modeles-container">
                <?php foreach ($modeles as $modele): ?>
                <div class="modele-card">
                    <div class="modele-header">
                        <div>
                            <div class="modele-title"><?php echo htmlspecialchars($modele['nom_modele']); ?></div>
                            <div class="modele-type"><?php echo ucfirst($modele['type_rapport']); ?></div>
                        </div>
                        <div class="modele-icon">
                            <?php 
                            $icons = [
                                'stock' => 'fas fa-boxes',
                                'ventes' => 'fas fa-shopping-cart',
                                'mouvements' => 'fas fa-exchange-alt',
                                'performance' => 'fas fa-chart-line'
                            ];
                            $icon = $icons[$modele['type_rapport']] ?? 'fas fa-chart-bar';
                            ?>
                            <i class="<?php echo $icon; ?>"></i>
                        </div>
                    </div>
                    <div class="modele-description">
                        Modèle prédéfini pour générer rapidement un rapport de type <?php echo $modele['type_rapport']; ?>.
                    </div>
                    <form method="POST" style="margin-top: 15px;">
                        <input type="hidden" name="modele_id" value="<?php echo $modele['id_modele']; ?>">
                        <button type="submit" name="utiliser_modele" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-play-circle"></i> Utiliser ce modèle
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Formulaire de génération de rapport -->
            <div class="table-container">
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="color: var(--primary); font-size: 1.5rem; font-weight: 600;">Générer un Nouveau Rapport</h2>
                </div>

                <form method="POST" id="formRapport">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label class="form-label">Titre du Rapport *</label>
                            <input type="text" name="titre" class="form-control" required placeholder="Ex: Rapport Stock Mensuel">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type de Rapport *</label>
                            <select name="type_rapport" id="type_rapport" class="form-select" required>
                                <option value="">Sélectionnez un type</option>
                                <option value="stock">Rapport de Stock</option>
                                <option value="ventes">Rapport de Ventes</option>
                                <option value="mouvements">Rapport de Mouvements</option>
                                <option value="performance">Rapport de Performance</option>
                                <option value="personnalise">Rapport Personnalisé</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Description du rapport..."></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label class="form-label">Période Début</label>
                            <input type="date" name="periode_debut" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Période Fin</label>
                            <input type="date" name="periode_fin" class="form-control">
                        </div>
                    </div>

                    <!-- Paramètres spécifiques selon le type de rapport -->
                    <div id="parametres_stock" class="parametres-section" style="display: none; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Paramètres Stock</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label class="form-label">Colonnes à inclure</label>
                                <div>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="colonnes_stock[]" value="nom_produit" checked> Nom Produit
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="colonnes_stock[]" value="quantite_stock" checked> Quantité Stock
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="colonnes_stock[]" value="seuil_alerte" checked> Seuil Alerte
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="colonnes_stock[]" value="valeur_stock"> Valeur Stock
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Filtres</label>
                                <div>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="seuil_alerte"> Produits sous seuil d'alerte
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="stock_zero"> Produits en rupture de stock
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="parametres_mouvements" class="parametres-section" style="display: none; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Paramètres Mouvements</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label class="form-label">Regrouper par</label>
                                <select name="group_by_mouvements" class="form-select">
                                    <option value="produit">Produit</option>
                                    <option value="jour">Jour</option>
                                    <option value="semaine">Semaine</option>
                                    <option value="mois">Mois</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Ordre</label>
                                <select name="ordre_mouvements" class="form-select">
                                    <option value="quantite_desc">Quantité décroissante</option>
                                    <option value="quantite_asc">Quantité croissante</option>
                                    <option value="date_desc">Date récente</option>
                                    <option value="date_asc">Date ancienne</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 30px;">
                        <button type="submit" name="generer_rapport" class="btn btn-primary" style="padding: 15px 40px; font-size: 16px;">
                            <i class="fas fa-cog"></i> Générer le Rapport
                        </button>
                    </div>
                </form>
            </div>

            <!-- Historique des rapports -->
            <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; margin-top: 40px;">
                <h2 style="color: var(--primary); font-size: 1.5rem; font-weight: 600;">Historique des Rapports</h2>
            </div>

            <!-- Filtres et recherche -->
            <div class="filters-container">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Rechercher</label>
                        <input type="text" name="search" class="form-control" placeholder="Titre ou description..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="">Tous les types</option>
                            <option value="stock" <?php echo $type_filter == 'stock' ? 'selected' : ''; ?>>Stock</option>
                            <option value="ventes" <?php echo $type_filter == 'ventes' ? 'selected' : ''; ?>>Ventes</option>
                            <option value="mouvements" <?php echo $type_filter == 'mouvements' ? 'selected' : ''; ?>>Mouvements</option>
                            <option value="performance" <?php echo $type_filter == 'performance' ? 'selected' : ''; ?>>Performance</option>
                            <option value="personnalise" <?php echo $type_filter == 'personnalise' ? 'selected' : ''; ?>>Personnalisé</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Statut</label>
                        <select name="statut" class="form-select">
                            <option value="">Tous les statuts</option>
                            <option value="en_cours" <?php echo $statut_filter == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                            <option value="termine" <?php echo $statut_filter == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                            <option value="erreur" <?php echo $statut_filter == 'erreur' ? 'selected' : ''; ?>>Erreur</option>
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
                            <option value="date_creation" <?php echo $sort_by === 'date_creation' ? 'selected' : ''; ?>>Date création</option>
                            <option value="titre" <?php echo $sort_by === 'titre' ? 'selected' : ''; ?>>Titre</option>
                            <option value="type_rapport" <?php echo $sort_by === 'type_rapport' ? 'selected' : ''; ?>>Type</option>
                            <option value="statut" <?php echo $sort_by === 'statut' ? 'selected' : ''; ?>>Statut</option>
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
                        <a href="rapports.php" class="btn" style="background: linear-gradient(135deg, #6c757d, #495057); color: white; margin-left: 10px;">
                            <i class="fas fa-times"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tableau des rapports -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Titre</th>
                            <th>Type</th>
                            <th>Statut</th>
                            <th>Date Création</th>
                            <th>Date Génération</th>
                            <th>Utilisateur</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rapports)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 30px; color: var(--gray);">Aucun rapport trouvé</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rapports as $rapport): ?>
                            <tr>
                                <td><?php echo $rapport['id_rapport']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($rapport['titre']); ?></strong>
                                    <?php if (!empty($rapport['description'])): ?>
                                        <br><small style="color: var(--gray);"><?php echo substr(htmlspecialchars($rapport['description']), 0, 50); ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="text-transform: capitalize;"><?php echo $rapport['type_rapport']; ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $rapport['statut']; ?>">
                                        <?php 
                                        $statuts = [
                                            'en_cours' => 'En Cours',
                                            'termine' => 'Terminé',
                                            'erreur' => 'Erreur'
                                        ];
                                        echo $statuts[$rapport['statut']];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($rapport['date_creation'])); ?>
                                </td>
                                <td>
                                    <?php if ($rapport['date_generation']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($rapport['date_generation'])); ?>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">En cours</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($rapport['nom_utilisateur']); ?>
                                </td>
                                <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <?php if ($rapport['statut'] === 'termine' && !empty($rapport['fichier_generer'])): ?>
                                    <a href="?telecharger=<?php echo $rapport['id_rapport']; ?>" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 8px 16px; font-size: 12px;">
                                        <i class="fas fa-download"></i> Télécharger
                                    </a>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm" onclick="supprimerRapport(<?php echo $rapport['id_rapport']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 8px 16px; font-size: 12px;">
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

    <script>
        // Graphique des types de rapports
        const typesCtx = document.getElementById('typesChart').getContext('2d');
        const typesChart = new Chart(typesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($stats_types, 'type_rapport')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($stats_types, 'nombre')); ?>,
                    backgroundColor: [
                        '#4361ee',
                        '#4cc9f0',
                        '#f72585',
                        '#7209b7',
                        '#4ade80'
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

        // Graphique de performance
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        const performanceChart = new Chart(performanceCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($stats_types, 'type_rapport')); ?>,
                datasets: [{
                    label: 'Temps moyen (minutes)',
                    data: <?php echo json_encode(array_column($stats_types, 'temps_moyen')); ?>,
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
                            text: 'Temps (minutes)'
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

        // Gestion des paramètres selon le type de rapport
        document.getElementById('type_rapport').addEventListener('change', function() {
            const type = this.value;
            
            // Masquer toutes les sections de paramètres
            document.querySelectorAll('.parametres-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Afficher la section correspondante
            if (type) {
                const sectionId = 'parametres_' + type;
                const section = document.getElementById(sectionId);
                if (section) {
                    section.style.display = 'block';
                }
            }
        });

        function supprimerRapport(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce rapport ? Cette action est irréversible.')) {
                window.location.href = 'rapports.php?supprimer=' + id;
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

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        // Initialiser l'affichage des paramètres si un type est présélectionné
        document.addEventListener('DOMContentLoaded', function() {
            const typeSelect = document.getElementById('type_rapport');
            if (typeSelect.value) {
                typeSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>