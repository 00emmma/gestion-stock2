<?php
// logout.php - Système de déconnexion STOCKFLOW
session_start();

// =====================================================================
// FONCTIONS DE RÉCUPÉRATION DES INFORMATIONS UTILISATEUR
// =====================================================================

/**
 * Récupère le nom de l'utilisateur selon la structure de session
 * @return string Le nom de l'utilisateur
 */
function getNomUtilisateur() {
    // Structure avec objet utilisateur (fichiers admin)
    if (isset($_SESSION['utilisateur']['nom']) && !empty($_SESSION['utilisateur']['nom'])) {
        return $_SESSION['utilisateur']['nom'];
    }
    // Structure moderne (nouveaux fichiers)
    elseif (isset($_SESSION['user_name']) && !empty($_SESSION['user_name'])) {
        return $_SESSION['user_name'];
    }
    // Structure alternative
    elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
        return $_SESSION['username'];
    }
    // Valeur par défaut
    else {
        return 'Utilisateur';
    }
}

/**
 * Récupère le rôle de l'utilisateur selon la structure de session
 * @return string Le rôle de l'utilisateur
 */
function getRoleUtilisateur() {
    // Structure avec objet utilisateur (fichiers admin)
    if (isset($_SESSION['utilisateur']['role']) && !empty($_SESSION['utilisateur']['role'])) {
        return $_SESSION['utilisateur']['role'];
    }
    // Structure moderne (nouveaux fichiers)
    elseif (isset($_SESSION['user_role']) && !empty($_SESSION['user_role'])) {
        return $_SESSION['user_role'];
    }
    // Structure alternative
    elseif (isset($_SESSION['role']) && !empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    // Déduction du rôle basé sur d'autres variables
    elseif (isset($_SESSION['utilisateur'])) {
        return 'admin'; // Probablement un admin
    }
    // Valeur par défaut
    else {
        return 'user';
    }
}

// =====================================================================
// RÉCUPÉRATION DES DONNÉES AVANT DESTRUCTION
// =====================================================================

$user_name = getNomUtilisateur();
$user_role = getRoleUtilisateur();

// =====================================================================
// JOURNALISATION DE LA DÉCONNEXION
// =====================================================================

$log_message = sprintf(
    "[DÉCONNEXION STOCKFLOW] User: %s (Role: %s) - IP: %s - Time: %s",
    $user_name,
    $user_role,
    $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    date('Y-m-d H:i:s')
);

// Décommenter pour activer les logs (utile pour debug)
// error_log($log_message);

// =====================================================================
// DESTRUCTION SÉCURISÉE DE LA SESSION
// =====================================================================

// Étape 1: Vider toutes les variables de session
$_SESSION = array();

// Étape 2: Détruire le cookie de session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"], 
        $params["secure"], 
        $params["httponly"]
    );
}

// Étape 3: Détruire la session côté serveur
session_destroy();

// =====================================================================
// PRÉPARATION DE LA REDIRECTION
// =====================================================================

// Construction des paramètres URL pour le message de déconnexion
$query_params = http_build_query([
    'logout' => '1',
    'user' => $user_name,
    'role' => $user_role,
    't' => time() // Timestamp pour éviter la mise en cache
]);

// URL de redirection (même dossier)
$redirect_url = "index.php?" . $query_params;

// =====================================================================
// REDIRECTION VERS LA PAGE D'ACCUEIL
// =====================================================================

// Headers de sécurité
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirection finale
header("Location: " . $redirect_url);
exit();
?>