<?php
// Views/admin/parametres.php
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

// Initialiser les variables
$success = '';
$error = '';
$utilisateurs = [];
$permissions = [];
$questions_securite = [];
$stats_utilisateurs = [];
$stats_questions = [
    'total_questions' => 0,
    'types_differents' => 0,
    'questions_utilisateurs' => 0,
    'utilisateurs_avec_questions' => 0
];

// CRÉATION DES TABLES SI ELLES N'EXISTENT PAS
$conn->query("
    CREATE TABLE IF NOT EXISTS utilisateurs (
        id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        mot_de_passe VARCHAR(255) NOT NULL,
        role ENUM('admin', 'gestionnaire', 'vendeur') DEFAULT 'vendeur',
        permissions TEXT,
        statut ENUM('actif', 'inactif') DEFAULT 'actif',
        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
        date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS permissions (
        id_permission INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        description TEXT,
        module VARCHAR(50) NOT NULL
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS questions_securite_config (
        id_question INT AUTO_INCREMENT PRIMARY KEY,
        question VARCHAR(255) NOT NULL,
        type_question VARCHAR(50) DEFAULT 'personnalise',
        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
        date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// INSÉRER LES DONNÉES PAR DÉFAUT
$permissions_par_defaut = [
    ['dashboard', 'Tableau de Bord', 'Accéder au tableau de bord'],
    ['categories', 'Gestion des Catégories', 'Gérer les catégories de produits'],
    ['produits', 'Gestion des Produits', 'Gérer les produits'],
    ['commandes', 'Gestion des Commandes', 'Gérer les commandes'],
    ['details_commande', 'Détails des Commandes', 'Voir les détails des commandes'],
    ['fournisseurs', 'Gestion des Fournisseurs', 'Gérer les fournisseurs'],
    ['utilisateurs', 'Gestion des Utilisateurs', 'Gérer les utilisateurs'],
    ['parametres', 'Paramètres', 'Accéder aux paramètres']
];

foreach ($permissions_par_defaut as $permission) {
    $stmt_check = $conn->prepare("SELECT id_permission FROM permissions WHERE module = ?");
    $stmt_check->bind_param("s", $permission[0]);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    
    if ($result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO permissions (module, nom, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $permission[0], $permission[1], $permission[2]);
        $stmt->execute();
    }
}

$questions_par_defaut = [
    ["Quel est le nom de votre ville de naissance ?", "ville_naissance"],
    ["Quel est le nom de votre animal de compagnie ?", "animal_compagnie"],
    ["Quel est votre film préféré ?", "film_prefere"],
    ["Quel est le nom de votre école primaire ?", "ecole_primaire"],
    ["Quel est le nom de jeune fille de votre mère ?", "mere_jeune_fille"]
];

foreach ($questions_par_defaut as $question) {
    $stmt_check = $conn->prepare("SELECT id_question FROM questions_securite_config WHERE question = ?");
    $stmt_check->bind_param("s", $question[0]);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    
    if ($result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO questions_securite_config (question, type_question) VALUES (?, ?)");
        $stmt->bind_param("ss", $question[0], $question[1]);
        $stmt->execute();
    }
}

// RÉCUPÉRER LES DONNÉES
// Utilisateurs
$stmt_utilisateurs = $conn->query("
    SELECT id_utilisateur, nom, email, role, permissions, statut, date_creation 
    FROM utilisateurs 
    ORDER BY nom
");
if ($stmt_utilisateurs) {
    $utilisateurs = $stmt_utilisateurs->fetch_all(MYSQLI_ASSOC);
}

// Permissions
$stmt_permissions = $conn->query("SELECT * FROM permissions ORDER BY module");
if ($stmt_permissions) {
    $permissions = $stmt_permissions->fetch_all(MYSQLI_ASSOC);
}

// Questions de sécurité
$stmt_questions = $conn->query("SELECT * FROM questions_securite_config ORDER BY date_creation DESC");
if ($stmt_questions) {
    $questions_securite = $stmt_questions->fetch_all(MYSQLI_ASSOC);
}

// Statistiques utilisateurs
$sql_stats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN role = 'gestionnaire' THEN 1 ELSE 0 END) as gestionnaires,
    SUM(CASE WHEN role = 'vendeur' THEN 1 ELSE 0 END) as vendeurs,
    SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
    SUM(CASE WHEN statut = 'inactif' THEN 1 ELSE 0 END) as inactifs
FROM utilisateurs";

$result_stats = $conn->query($sql_stats);
if ($result_stats) {
    $stats_utilisateurs = $result_stats->fetch_assoc();
}

// Statistiques questions (avec gestion d'erreurs)
try {
    // Questions configurées
    $result = $conn->query("SELECT COUNT(*) as total FROM questions_securite_config");
    if ($result) {
        $stats_questions['total_questions'] = $result->fetch_assoc()['total'] ?? 0;
    }
    
    // Types différents
    $result = $conn->query("SELECT COUNT(DISTINCT type_question) as types FROM questions_securite_config");
    if ($result) {
        $stats_questions['types_differents'] = $result->fetch_assoc()['types'] ?? 0;
    }
    
    // Questions utilisateurs
    $result = $conn->query("SELECT COUNT(*) as total FROM questions_securite");
    if ($result) {
        $stats_questions['questions_utilisateurs'] = $result->fetch_assoc()['total'] ?? 0;
    }
    
    // Utilisateurs avec questions
    $result = $conn->query("SELECT COUNT(DISTINCT id_utilisateur) as users FROM questions_securite");
    if ($result) {
        $stats_questions['utilisateurs_avec_questions'] = $result->fetch_assoc()['users'] ?? 0;
    }
} catch (Exception $e) {
    // Les valeurs par défaut restent à 0
}

// GESTION DES ACTIONS
// Ajouter un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_utilisateur'])) {
    $nom = trim($_POST['nom']);
    $email = trim($_POST['email']);
    $mot_de_passe_input = $_POST['mot_de_passe'] ?? '';
    $role = $_POST['role'];
    $permissions_utilisateur = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : '[]';
    $statut = $_POST['statut'];

    // Validation des champs obligatoires
    if (empty($nom) || empty($email) || empty($mot_de_passe_input)) {
        $error = "Tous les champs obligatoires doivent être remplis.";
    } elseif (strlen($mot_de_passe_input) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } else {
        // Vérifier si l'email existe déjà
        $stmt_check = $conn->prepare("SELECT id_utilisateur FROM utilisateurs WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $error = "Un utilisateur avec cet email existe déjà.";
        } else {
            $mot_de_passe = password_hash($mot_de_passe_input, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role, permissions, statut) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $nom, $email, $mot_de_passe, $role, $permissions_utilisateur, $statut);
            
            if ($stmt->execute()) {
                $success = "Utilisateur ajouté avec succès!";
                header('Location: parametres.php?success=1');
                exit();
            } else {
                $error = "Erreur lors de l'ajout de l'utilisateur.";
            }
        }
    }
}

// Réinitialiser le mot de passe d'un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reinitialiser_mdp'])) {
    $id_utilisateur = $_POST['id_utilisateur'];
    $nouveau_mot_de_passe = password_hash('password123', PASSWORD_DEFAULT); // Mot de passe par défaut
    
    $stmt = $conn->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id_utilisateur = ?");
    $stmt->bind_param("si", $nouveau_mot_de_passe, $id_utilisateur);
    
    if ($stmt->execute()) {
        $success = "Mot de passe réinitialisé avec succès! Le nouveau mot de passe est: password123";
        header('Location: parametres.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la réinitialisation du mot de passe.";
    }
}

// Ajouter une question de sécurité
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_questions']) && $_POST['action_questions'] === 'ajouter_question') {
    $question = trim($_POST['question']);
    $type_question = $_POST['type_question'];
    
    if (!empty($question)) {
        $stmt = $conn->prepare("INSERT INTO questions_securite_config (question, type_question) VALUES (?, ?)");
        $stmt->bind_param("ss", $question, $type_question);
        
        if ($stmt->execute()) {
            $success = "Question de sécurité ajoutée avec succès!";
            header('Location: parametres.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de l'ajout de la question.";
        }
    } else {
        $error = "Veuillez saisir une question.";
    }
}

// Supprimer un utilisateur
if (isset($_GET['supprimer'])) {
    $id_utilisateur = $_GET['supprimer'];
    
    if ($id_utilisateur == $_SESSION['utilisateur']['id_utilisateur']) {
        $error = "Vous ne pouvez pas supprimer votre propre compte.";
    } else {
        $stmt = $conn->prepare("DELETE FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->bind_param("i", $id_utilisateur);
        
        if ($stmt->execute()) {
            $success = "Utilisateur supprimé avec succès!";
            header('Location: parametres.php?success=1');
            exit();
        } else {
            $error = "Erreur lors de la suppression de l'utilisateur.";
        }
    }
}

// Supprimer une question
if (isset($_GET['supprimer_question'])) {
    $id_question = $_GET['supprimer_question'];
    
    $stmt = $conn->prepare("DELETE FROM questions_securite_config WHERE id_question = ?");
    $stmt->bind_param("i", $id_question);
    
    if ($stmt->execute()) {
        $success = "Question supprimée avec succès!";
        header('Location: parametres.php?success=1');
        exit();
    } else {
        $error = "Erreur lors de la suppression de la question.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCKFLOW | Paramètres</title>
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

        .tabs {
            display: flex;
            background: white;
            border-radius: 15px;
            padding: 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .tab {
            padding: 18px 30px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transition: left 0.3s ease;
            z-index: -1;
        }

        .tab.active {
            color: white;
        }

        .tab.active::before {
            left: 0;
        }

        .tab:hover {
            color: var(--primary);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .role-admin {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .role-gestionnaire {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

        .role-vendeur {
            background: linear-gradient(135deg, #f72585, #b5179e);
            color: white;
        }

        .badge-success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

        .badge-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
        }

        .questions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .question-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .question-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transform: scaleX(1);
        }

        .question-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .question-text {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
            line-height: 1.4;
            flex: 1;
        }

        .question-type {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-left: 10px;
        }

        .question-date {
            color: var(--gray);
            font-size: 0.8rem;
            margin-top: 5px;
        }

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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #b5179e);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            padding: 20px 30px 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .modal-title {
            color: var(--primary);
            font-size: 18px;
            margin: 0;
            font-weight: 600;
        }

        .close {
            position: absolute;
            right: 15px;
            top: 15px;
            color: var(--gray);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            z-index: 11;
        }

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

            .tabs {
                flex-direction: column;
            }

            .tab {
                text-align: center;
                border-bottom: 1px solid rgba(67, 97, 238, 0.1);
                border-left: 4px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary);
                border-bottom-color: rgba(67, 97, 238, 0.1);
            }

            .questions-grid {
                grid-template-columns: 1fr;
            }
        }

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

        .stat-card, .chart-card, .table-container, .question-card {
            animation: fadeInUp 0.6s ease-out;
        }

        .required-field::after {
            content: " *";
            color: var(--danger);
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                <i class="fas fa-truck"></i>
                <span>Fournisseurs</span>
            </a>
            <a href="rapports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="utilisateurs.php" class="nav-item">
                <i class="fas fa-users-cog"></i>
                <span>Utilisateurs</span>
            </a>
            <a href="parametres.php" class="nav-item active">
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
                <h1>Paramètres du Système</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></p>
                        <small style="color: var(--gray);">Administrateur</small>
                    </div>
                </div>
            </div>

            <!-- Messages d'alerte -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);">
                    <i class="fas fa-check-circle"></i> Opération effectuée avec succès!
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Statistiques -->
            <div class="stats-grid">
                <div class="stat-card fade-in-up" style="animation-delay: 0.1s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Utilisateurs</h3>
                        <div class="stat-value"><?php echo $stats_utilisateurs['total'] ?? 0; ?></div>
                        <div class="stat-description">Total</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.2s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4cc9f0, #4895ef);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Administrateurs</h3>
                        <div class="stat-value"><?php echo $stats_utilisateurs['admins'] ?? 0; ?></div>
                        <div class="stat-description">En système</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.3s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #b5179e);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Utilisateurs Actifs</h3>
                        <div class="stat-value"><?php echo $stats_utilisateurs['actifs'] ?? 0; ?></div>
                        <div class="stat-description">En activité</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.4s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Questions Sécurité</h3>
                        <div class="stat-value"><?php echo $stats_questions['total_questions']; ?></div>
                        <div class="stat-description">Configurées</div>
                    </div>
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
                        <h3 class="chart-title">Statut des Utilisateurs</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="statutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Onglets -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('tab-utilisateurs')">
                    <i class="fas fa-users"></i> Gestion des Utilisateurs
                </button>
                <button class="tab" onclick="openTab('tab-permissions')">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>
                <button class="tab" onclick="openTab('tab-questions')">
                    <i class="fas fa-question-circle"></i> Questions Sécurité
                </button>
                <button class="tab" onclick="openTab('tab-systeme')">
                    <i class="fas fa-cogs"></i> Paramètres Système
                </button>
            </div>

            <!-- Onglet Utilisateurs -->
            <div id="tab-utilisateurs" class="tab-content active">
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                    <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Gestion des Utilisateurs</h2>
                    <button class="btn btn-primary" onclick="openModal('modalAjouter')">
                        <i class="fas fa-plus"></i> Nouvel Utilisateur
                    </button>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Statut</th>
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
                                        <span class="badge role-<?php echo $utilisateur['role']; ?>">
                                            <?php echo ucfirst($utilisateur['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $utilisateur['statut'] === 'actif' ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo ucfirst($utilisateur['statut']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y H:i', strtotime($utilisateur['date_creation'])); ?>
                                    </td>
                                    <td class="actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <button class="btn btn-warning btn-sm" onclick="modifierUtilisateur(<?php echo $utilisateur['id_utilisateur']; ?>)" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white; padding: 8px 16px; font-size: 12px;">
                                            <i class="fas fa-edit"></i> Modifier
                                        </button>
                                        <button class="btn btn-info btn-sm" onclick="reinitialiserMDP(<?php echo $utilisateur['id_utilisateur']; ?>)" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 8px 16px; font-size: 12px;">
                                            <i class="fas fa-key"></i> Réinit MDP
                                        </button>
                                        <?php if ($utilisateur['id_utilisateur'] != $_SESSION['utilisateur']['id_utilisateur']): ?>
                                        <button class="btn btn-danger btn-sm" onclick="supprimerUtilisateur(<?php echo $utilisateur['id_utilisateur']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 8px 16px; font-size: 12px;">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet Permissions -->
            <div id="tab-permissions" class="tab-content">
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                    <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Gestion des Permissions</h2>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Nom</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($permissions)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 30px; color: var(--gray);">Aucune permission trouvée</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($permissions as $permission): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($permission['module']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($permission['nom']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet Questions de Sécurité -->
            <div id="tab-questions" class="tab-content">
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                    <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Questions de Sécurité</h2>
                    <button class="btn btn-primary" onclick="openModal('modalAjouterQuestion')">
                        <i class="fas fa-plus"></i> Nouvelle Question
                    </button>
                </div>

                <!-- Statistiques des Questions -->
                <div class="stats-grid" style="margin-bottom: 30px;">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Questions Configurées</h3>
                            <div class="stat-value"><?php echo $stats_questions['total_questions']; ?></div>
                            <div class="stat-description">Total</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4cc9f0, #4895ef);">
                            <i class="fas fa-tags"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Types Différents</h3>
                            <div class="stat-value"><?php echo $stats_questions['types_differents']; ?></div>
                            <div class="stat-description">Catégories</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #b5179e);">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Utilisateurs Protégés</h3>
                            <div class="stat-value"><?php echo $stats_questions['utilisateurs_avec_questions']; ?></div>
                            <div class="stat-description">Avec questions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Questions Utilisateurs</h3>
                            <div class="stat-value"><?php echo $stats_questions['questions_utilisateurs']; ?></div>
                            <div class="stat-description">Configurées</div>
                        </div>
                    </div>
                </div>

                <!-- Liste des Questions -->
                <div class="table-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">Questions de Sécurité Configurées</h3>
                    
                    <?php if (empty($questions_securite)): ?>
                        <div class="empty-state">
                            <i class="fas fa-question-circle"></i>
                            <h4>Aucune question configurée</h4>
                            <p>Ajoutez des questions de sécurité pour permettre la réinitialisation des mots de passe.</p>
                        </div>
                    <?php else: ?>
                        <div class="questions-grid">
                            <?php foreach ($questions_securite as $question): ?>
                            <div class="question-card">
                                <div class="question-header">
                                    <div class="question-text"><?php echo htmlspecialchars($question['question']); ?></div>
                                    <span class="question-type"><?php echo htmlspecialchars($question['type_question']); ?></span>
                                </div>
                                <div class="question-date">
                                    Créée le: <?php echo date('d/m/Y à H:i', strtotime($question['date_creation'])); ?>
                                </div>
                                <div style="display: flex; gap: 8px; margin-top: 15px;">
                                    <button class="btn btn-warning btn-sm" onclick="modifierQuestion(<?php echo $question['id_question']; ?>)" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white; padding: 6px 12px; font-size: 12px;">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="supprimerQuestion(<?php echo $question['id_question']; ?>)" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white; padding: 6px 12px; font-size: 12px;">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Onglet Système -->
            <div id="tab-systeme" class="tab-content">
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
                    <h2 class="page-title" style="color: var(--primary); font-size: 1.8rem; font-weight: 600;">Paramètres Système</h2>
                </div>

                <div class="info-card" style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); margin-bottom: 20px; border: 1px solid rgba(67, 97, 238, 0.1);">
                    <h3 style="margin-bottom: 20px; color: var(--primary); font-size: 1.3rem;">
                        <i class="fas fa-info-circle"></i> Informations Système
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div style="padding: 15px; background: rgba(67, 97, 238, 0.05); border-radius: 10px;">
                            <strong style="color: var(--primary);">Version:</strong> STOCKFLOW 1.0
                        </div>
                        <div style="padding: 15px; background: rgba(67, 97, 238, 0.05); border-radius: 10px;">
                            <strong style="color: var(--primary);">Base de données:</strong> MySQL
                        </div>
                        <div style="padding: 15px; background: rgba(67, 97, 238, 0.05); border-radius: 10px;">
                            <strong style="color: var(--primary);">Serveur:</strong> Apache/PHP
                        </div>
                        <div style="padding: 15px; background: rgba(67, 97, 238, 0.05); border-radius: 10px;">
                            <strong style="color: var(--primary);">Dernière mise à jour:</strong> <?php echo date('d/m/Y'); ?>
                        </div>
                    </div>
                </div>

                <div class="info-card" style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); border: 1px solid rgba(67, 97, 238, 0.1);">
                    <h3 style="margin-bottom: 20px; color: var(--primary); font-size: 1.3rem;">
                        <i class="fas fa-database"></i> Maintenance
                    </h3>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <button class="btn" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white;">
                            <i class="fas fa-download"></i> Sauvegarder la base
                        </button>
                        <button class="btn" style="background: linear-gradient(135deg, var(--warning), #b5179e); color: white;">
                            <i class="fas fa-sync"></i> Vérifier l'intégrité
                        </button>
                        <button class="btn" style="background: linear-gradient(135deg, var(--danger), #c1121f); color: white;">
                            <i class="fas fa-trash"></i> Nettoyer les logs
                        </button>
                    </div>
                </div>
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
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Nom complet</label>
                    <input type="text" name="nom" class="form-control" required maxlength="100" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Email</label>
                    <input type="email" name="email" class="form-control" required maxlength="100" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Mot de passe</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="password" name="mot_de_passe" id="mot_de_passe" class="form-control" required minlength="6" style="flex: 1; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                        <button type="button" class="btn" onclick="genererMotDePasse()" style="background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; padding: 12px; white-space: nowrap;">
                            <i class="fas fa-sync-alt"></i> Générer
                        </button>
                    </div>
                    <small style="color: var(--gray); margin-top: 5px; display: block;">
                        <i class="fas fa-info-circle"></i> Le mot de passe doit contenir au moins 6 caractères
                    </small>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Rôle</label>
                    <select name="role" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; background-color: white;">
                        <option value="admin">Administrateur</option>
                        <option value="gestionnaire">Gestionnaire</option>
                        <option value="vendeur" selected>Vendeur</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Permissions</label>
                    <div class="permissions-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                        <?php foreach ($permissions as $permission): ?>
                        <div class="permission-item" style="display: flex; align-items: center; gap: 8px; padding: 10px; border: 1px solid #e9ecef; border-radius: 8px; background: #f8f9fa;">
                            <input type="checkbox" name="permissions[]" value="<?php echo $permission['module']; ?>" 
                                   id="perm_<?php echo $permission['module']; ?>" style="margin: 0;">
                            <label for="perm_<?php echo $permission['module']; ?>" style="margin: 0; cursor: pointer; font-size: 14px;">
                                <?php echo htmlspecialchars($permission['nom']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dark);">Statut</label>
                    <select name="statut" class="form-select" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; background-color: white;">
                        <option value="actif" selected>Actif</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouter')" 
                            style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" name="ajouter_utilisateur" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ajouter Question -->
    <div id="modalAjouterQuestion" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Ajouter une Question de Sécurité</h3>
                <button class="close" onclick="closeModal('modalAjouterQuestion')">&times;</button>
            </div>
            <form method="POST" style="padding: 0 30px 30px;">
                <input type="hidden" name="action_questions" value="ajouter_question">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Question</label>
                    <input type="text" name="question" required 
                           placeholder="Ex: Quel est le nom de votre ville de naissance ?"
                           style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label required-field" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark);">Type de Question</label>
                    <select name="type_question" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; background-color: white;">
                        <option value="ville_naissance">Ville de naissance</option>
                        <option value="animal_compagnie">Animal de compagnie</option>
                        <option value="film_prefere">Film préféré</option>
                        <option value="ecole_primaire">École primaire</option>
                        <option value="mere_jeune_fille">Nom de jeune fille de la mère</option>
                        <option value="personnalise">Personnalisé</option>
                    </select>
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; border-top: 1px solid rgba(67, 97, 238, 0.1); padding-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('modalAjouterQuestion')" 
                            style="background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 12px 24px;">Annuler</button>
                    <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">
                        <i class="fas fa-plus"></i> Ajouter la Question
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Graphique des rôles
        const rolesCtx = document.getElementById('rolesChart').getContext('2d');
        const rolesChart = new Chart(rolesCtx, {
            type: 'doughnut',
            data: {
                labels: ['Administrateurs', 'Gestionnaires', 'Vendeurs'],
                datasets: [{
                    data: [
                        <?php echo $stats_utilisateurs['admins'] ?? 0; ?>,
                        <?php echo $stats_utilisateurs['gestionnaires'] ?? 0; ?>,
                        <?php echo $stats_utilisateurs['vendeurs'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#4361ee',
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

        // Graphique du statut
        const statutCtx = document.getElementById('statutChart').getContext('2d');
        const statutChart = new Chart(statutCtx, {
            type: 'pie',
            data: {
                labels: ['Actifs', 'Inactifs'],
                datasets: [{
                    data: [
                        <?php echo $stats_utilisateurs['actifs'] ?? 0; ?>,
                        <?php echo $stats_utilisateurs['inactifs'] ?? 0; ?>
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

        // Gestion des onglets
        function openTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Gestion des modals
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function modifierUtilisateur(id) {
            window.location.href = `parametres.php?modifier=${id}`;
        }

        function supprimerUtilisateur(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')) {
                window.location.href = `parametres.php?supprimer=${id}`;
            }
        }

        function reinitialiserMDP(id) {
            if (confirm('Êtes-vous sûr de vouloir réinitialiser le mot de passe de cet utilisateur ?\nLe nouveau mot de passe sera: password123\n\nPensez à informer l\'utilisateur du changement !')) {
                // Créer un formulaire dynamique pour envoyer la requête POST
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'reinitialiser_mdp';
                inputAction.value = '1';
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id_utilisateur';
                inputId.value = id;
                
                form.appendChild(inputAction);
                form.appendChild(inputId);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function supprimerQuestion(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette question ? Cette action est irréversible.')) {
                window.location.href = `parametres.php?supprimer_question=${id}`;
            }
        }

        function modifierQuestion(id) {
            // Implémenter la modification de question
            alert('Fonction de modification à implémenter pour la question ID: ' + id);
        }

        function genererMotDePasse() {
            const caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
            let motDePasse = '';
            for (let i = 0; i < 12; i++) {
                motDePasse += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
            }
            
            document.getElementById('mot_de_passe').value = motDePasse;
            
            // Afficher le mot de passe généré
            alert('Mot de passe généré : ' + motDePasse + '\n\nPensez à le copier !');
        }

        function logout() {
            if (confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
                window.location.href = '../../logout.php';
            }
        }

        // Validation du formulaire d'ajout d'utilisateur
        document.addEventListener('DOMContentLoaded', function() {
            const formAjouter = document.querySelector('form[method="POST"]');
            if (formAjouter) {
                formAjouter.addEventListener('submit', function(e) {
                    const motDePasse = document.querySelector('input[name="mot_de_passe"]');
                    const nom = document.querySelector('input[name="nom"]');
                    const email = document.querySelector('input[name="email"]');
                    
                    if (!nom.value.trim()) {
                        e.preventDefault();
                        alert('Le nom est obligatoire');
                        nom.focus();
                        return;
                    }
                    
                    if (!email.value.trim()) {
                        e.preventDefault();
                        alert('L\'email est obligatoire');
                        email.focus();
                        return;
                    }
                    
                    if (!motDePasse.value) {
                        e.preventDefault();
                        alert('Le mot de passe est obligatoire');
                        motDePasse.focus();
                        return;
                    }
                    
                    if (motDePasse.value.length < 6) {
                        e.preventDefault();
                        alert('Le mot de passe doit contenir au moins 6 caractères');
                        motDePasse.focus();
                        return;
                    }
                });
            }
        });

        // Fermer les messages d'alerte après 5 secondes
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Fermer les modals en cliquant en dehors
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
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
    </script>
</body>
</html>
<?php
$conn->close();
?>