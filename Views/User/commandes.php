<?php
// Views/user/commandes.php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../../index.php');
    exit();
}

$conn = new mysqli("localhost", "root", "", "stoch_db");
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}

// Vérifier la structure des tables
$table_columns = [];

// Vérifier les colonnes de la table produits
$result = $conn->query("SHOW COLUMNS FROM produits");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $table_columns['produits'][] = $row['Field'];
    }
}

// Vérifier les colonnes de la table commandes
$result = $conn->query("SHOW COLUMNS FROM commandes");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $table_columns['commandes'][] = $row['Field'];
    }
}

// Vérifier si la table clients existe
$table_clients_exists = false;
$result = $conn->query("SHOW TABLES LIKE 'clients'");
if ($result->num_rows > 0) {
    $table_clients_exists = true;
}

// Déterminer les noms de colonnes réels
$nom_column = 'nom_produit';
$prix_column = 'prix_unitaire';
$stock_column = 'quantite';

// Chercher les colonnes existantes
$possible_nom_columns = ['nom_produit', 'nom', 'name', 'libelle', 'designation', 'product_name'];
$possible_prix_columns = ['prix_unitaire', 'prix', 'price', 'prix_vente', 'selling_price'];
$possible_stock_columns = ['quantite', 'quantite_stock', 'stock', 'quantity', 'qte_stock'];

foreach ($possible_nom_columns as $col) {
    if (in_array($col, $table_columns['produits'])) {
        $nom_column = $col;
        break;
    }
}

foreach ($possible_prix_columns as $col) {
    if (in_array($col, $table_columns['produits'])) {
        $prix_column = $col;
        break;
    }
}

foreach ($possible_stock_columns as $col) {
    if (in_array($col, $table_columns['produits'])) {
        $stock_column = $col;
        break;
    }
}

// Si aucune colonne nom n'est trouvée, utiliser l'ID comme fallback
if (!in_array($nom_column, $table_columns['produits'])) {
    $nom_column = 'id_produit';
}

// Gestion de la création d'une nouvelle commande
$action = $_GET['action'] ?? 'liste';
$panier = $_SESSION['panier'] ?? [];

// Ajouter un produit au panier
if (isset($_POST['ajouter_au_panier'])) {
    $id_produit = $_POST['id_produit'];
    $quantite = intval($_POST['quantite']);
    
    // Construire la requête dynamiquement
    $select_columns = "id_produit";
    if (in_array($nom_column, $table_columns['produits'])) {
        $select_columns .= ", $nom_column as nom";
    } else {
        $select_columns .= ", CONCAT('Produit #', id_produit) as nom";
    }
    
    if (in_array($prix_column, $table_columns['produits'])) {
        $select_columns .= ", $prix_column as prix";
    } else {
        $select_columns .= ", 0 as prix";
    }
    
    if (in_array($stock_column, $table_columns['produits'])) {
        $select_columns .= ", $stock_column as stock";
    } else {
        $select_columns .= ", 0 as stock";
    }
    
    // Vérifier si le produit existe et a du stock
    $query = "SELECT $select_columns FROM produits WHERE id_produit = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id_produit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $produit = $result->fetch_assoc();
        
        $stock_disponible = $produit['stock'] ?? 0;
        
        if ($stock_disponible >= $quantite) {
            // Ajouter au panier
            if (isset($panier[$id_produit])) {
                $panier[$id_produit]['quantite'] += $quantite;
            } else {
                $panier[$id_produit] = [
                    'nom' => $produit['nom'],
                    'prix' => $produit['prix'] ?? 0,
                    'quantite' => $quantite,
                    'stock_disponible' => $stock_disponible
                ];
            }
            $_SESSION['panier'] = $panier;
            $success = "Produit ajouté au panier avec succès!";
        } else {
            $error = "Stock insuffisant pour ce produit. Stock disponible: " . $stock_disponible;
        }
    } else {
        $error = "Produit non trouvé!";
    }
    $stmt->close();
}

// Modifier la quantité dans le panier
if (isset($_POST['modifier_quantite'])) {
    $id_produit = $_POST['id_produit'];
    $quantite = intval($_POST['quantite']);
    
    if ($quantite > 0) {
        // Vérifier le stock
        if (in_array($stock_column, $table_columns['produits'])) {
            $stmt = $conn->prepare("SELECT $stock_column as stock FROM produits WHERE id_produit = ?");
            $stmt->bind_param("i", $id_produit);
            $stmt->execute();
            $result = $stmt->get_result();
            $produit = $result->fetch_assoc();
            $stock_disponible = $produit['stock'] ?? 0;
            $stmt->close();
        } else {
            $stock_disponible = 1000; // Valeur par défaut si pas de colonne stock
        }
        
        if ($stock_disponible >= $quantite) {
            $panier[$id_produit]['quantite'] = $quantite;
            $_SESSION['panier'] = $panier;
            $success = "Quantité modifiée avec succès!";
        } else {
            $error = "Stock insuffisant. Stock disponible: " . $stock_disponible;
        }
    } else {
        // Supprimer le produit si quantité = 0
        unset($panier[$id_produit]);
        $_SESSION['panier'] = $panier;
        $success = "Produit retiré du panier!";
    }
}

// Supprimer un produit du panier
if (isset($_POST['supprimer_du_panier'])) {
    $id_produit = $_POST['id_produit'];
    unset($panier[$id_produit]);
    $_SESSION['panier'] = $panier;
    $success = "Produit retiré du panier!";
}

// Vider le panier
if (isset($_POST['vider_panier'])) {
    $_SESSION['panier'] = [];
    $panier = [];
    $success = "Panier vidé avec succès!";
}

// Finaliser la commande
if (isset($_POST['finaliser_commande'])) {
    if (!empty($panier)) {
        // Calculer le total
        $total = 0;
        foreach ($panier as $item) {
            $total += $item['prix'] * $item['quantite'];
        }
        
        // Déterminer les colonnes disponibles pour l'insertion
        $columns_commandes = [];
        $placeholders = [];
        $values = [];
        $types = '';
        
        // Chercher une colonne pour la référence
        $possible_reference_columns = ['reference', 'ref', 'numero', 'code', 'id_commande'];
        $reference_column = null;
        foreach ($possible_reference_columns as $col) {
            if (in_array($col, $table_columns['commandes'])) {
                $reference_column = $col;
                break;
            }
        }
        
        // Générer une valeur pour la référence
        $reference_value = 'CMD-' . date('Ymd-His') . '-' . rand(100, 999);
        
        // Si on a trouvé une colonne référence, l'ajouter
        if ($reference_column) {
            $columns_commandes[] = $reference_column;
            $placeholders[] = '?';
            $values[] = $reference_value;
            $types .= 's';
        }
        
        // Chercher une colonne pour le total
        $possible_total_columns = ['total', 'montant', 'amount', 'prix_total'];
        $total_column = null;
        foreach ($possible_total_columns as $col) {
            if (in_array($col, $table_columns['commandes'])) {
                $total_column = $col;
                break;
            }
        }
        
        // Ajouter le total si la colonne existe
        if ($total_column) {
            $columns_commandes[] = $total_column;
            $placeholders[] = '?';
            $values[] = $total;
            $types .= 'd';
        }
        
        // Chercher une colonne pour le statut
        $possible_statut_columns = ['statut', 'status', 'etat', 'state'];
        $statut_column = null;
        foreach ($possible_statut_columns as $col) {
            if (in_array($col, $table_columns['commandes'])) {
                $statut_column = $col;
                break;
            }
        }
        
        // Ajouter le statut si la colonne existe
        if ($statut_column) {
            $columns_commandes[] = $statut_column;
            $placeholders[] = '?';
            $values[] = 'en cours';
            $types .= 's';
        }
        
        // Ajouter date_creation si la colonne existe
        if (in_array('date_creation', $table_columns['commandes'])) {
            $columns_commandes[] = 'date_creation';
            $placeholders[] = 'NOW()';
        } elseif (in_array('created_at', $table_columns['commandes'])) {
            $columns_commandes[] = 'created_at';
            $placeholders[] = 'NOW()';
        } elseif (in_array('date_commande', $table_columns['commandes'])) {
            $columns_commandes[] = 'date_commande';
            $placeholders[] = 'NOW()';
        }
        
        // Si aucune colonne n'est trouvée, utiliser les colonnes de base
        if (empty($columns_commandes)) {
            // Vérifier s'il y a au moins l'ID (auto-incrément)
            $columns_commandes[] = 'id_commande';
            $placeholders[] = 'NULL'; // Laisser l'auto-incrémentation gérer
        }
        
        // Construire la requête d'insertion
        $query = "INSERT INTO commandes (" . implode(', ', $columns_commandes) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        // Préparer et exécuter la requête
        $stmt = $conn->prepare($query);
        
        // Lier les paramètres seulement s'il y a des placeholders '?'
        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }
        
        if ($stmt->execute()) {
            $id_commande = $conn->insert_id;
            
            // Mettre à jour les stocks si la colonne existe
            if (in_array($stock_column, $table_columns['produits'])) {
                foreach ($panier as $id_produit => $item) {
                    $stmt_update = $conn->prepare("UPDATE produits SET $stock_column = $stock_column - ? WHERE id_produit = ?");
                    $stmt_update->bind_param("ii", $item['quantite'], $id_produit);
                    $stmt_update->execute();
                    $stmt_update->close();
                }
            }
            
            // Vider le panier
            $_SESSION['panier'] = [];
            $panier = [];
            
            $success = "Commande créée avec succès! " . ($reference_column ? "Référence: #$reference_value" : "ID: #$id_commande") . " - Total: €" . number_format($total, 2);
        } else {
            $error = "Erreur lors de la création de la commande: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Le panier est vide!";
    }
}

// Récupérer les produits disponibles
$produits = [];

// Construire la requête de sélection des produits
$select_columns = "id_produit";
if (in_array($nom_column, $table_columns['produits'])) {
    $select_columns .= ", $nom_column as nom";
} else {
    $select_columns .= ", CONCAT('Produit #', id_produit) as nom";
}

if (in_array($prix_column, $table_columns['produits'])) {
    $select_columns .= ", $prix_column as prix";
} else {
    $select_columns .= ", 0 as prix";
}

if (in_array($stock_column, $table_columns['produits'])) {
    $select_columns .= ", $stock_column as stock";
    $where_condition = "WHERE $stock_column > 0";
} else {
    $select_columns .= ", 1000 as stock"; // Valeur par défaut
    $where_condition = "";
}

$query = "SELECT $select_columns FROM produits $where_condition ORDER BY id_produit ASC";

$stmt_produits = $conn->prepare($query);
if ($stmt_produits) {
    $stmt_produits->execute();
    $result_produits = $stmt_produits->get_result();
    $produits = $result_produits->fetch_all(MYSQLI_ASSOC);
}

// Calculer le total du panier
$total_panier = 0;
$nombre_articles = 0;
foreach ($panier as $item) {
    $total_panier += $item['prix'] * $item['quantite'];
    $nombre_articles += $item['quantite'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Commandes - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --accent: #f72585;
            --success: #4cc9f0;
            --warning: #f7b801;
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
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
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
            backdrop-filter: blur(20px);
            color: var(--dark);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
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

        /* Contenu principal */
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

        /* Header amélioré */
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

        /* Cartes de statistiques améliorées */
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

        /* Section produits */
        .products-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .section-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Grille de produits */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .product-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .product-card:hover::before {
            transform: scaleX(1);
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .product-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 1.1rem;
            line-height: 1.3;
        }

        .product-stock {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .product-price {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .product-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .quantity-input {
            width: 80px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 8px 12px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .quantity-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .add-to-cart-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            flex: 1;
        }

        .add-to-cart-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }

        /* Panier */
        .cart-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .cart-items {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 25px;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            background: rgba(67, 97, 238, 0.03);
            border-radius: 10px;
            padding: 15px;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .item-price {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .item-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .item-quantity {
            width: 70px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 6px 10px;
            text-align: center;
            font-weight: 600;
        }

        .update-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .update-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(67, 97, 238, 0.3);
        }

        .remove-btn {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .remove-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(230, 57, 70, 0.3);
        }

        .cart-total {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
        }

        .total-amount {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .total-label {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .total-items {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .checkout-btn {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .checkout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(76, 201, 240, 0.4);
        }

        .clear-cart-btn {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }

        .clear-cart-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(230, 57, 70, 0.3);
        }

        /* État vide */
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

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .logo h1, .welcome, .nav-item span, .logout-btn span {
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

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-card {
                padding: 25px;
            }

            .stat-value {
                font-size: 2.2rem;
            }

            .products-grid {
                grid-template-columns: 1fr;
            }

            .cart-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .item-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .product-form {
                flex-direction: column;
            }

            .quantity-input {
                width: 100%;
            }
        }
    </style>
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
            <p>Utilisateur</p>
        </div>

        <ul class="nav-menu">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de Bord</span>
            </a>
            <a href="produits.php" class="nav-item">
                <i class="fas fa-boxes"></i>
                <span>Produits</span>
            </a>
            <a href="commandes.php" class="nav-item active">
                <i class="fas fa-shopping-cart"></i>
                <span>Commandes</span>
            </a>
            <a href="rapports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="parametres.php" class="nav-item">
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
                <h1>Nouvelle Commande</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></p>
                        <small style="color: var(--gray);">Utilisateur</small>
                    </div>
                </div>
            </div>

            <!-- Messages d'alerte -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle me-2"></i>
                        <div><?php echo $success; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <div><?php echo $error; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistiques rapides -->
            <div class="stats-grid">
                <div class="stat-card fade-in-up" style="animation-delay: 0.1s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Produits Disponibles</h3>
                        <div class="stat-value"><?php echo count($produits); ?></div>
                        <div class="stat-description">En stock</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.2s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #f7b801);">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Articles Panier</h3>
                        <div class="stat-value"><?php echo $nombre_articles; ?></div>
                        <div class="stat-description">En attente</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.3s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Panier</h3>
                        <div class="stat-value">€<?php echo number_format($total_panier, 2); ?></div>
                        <div class="stat-description">Montant total</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.4s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent), #f72585);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Commande</h3>
                        <div class="stat-value"><?php echo count($panier); ?></div>
                        <div class="stat-description">Produits sélectionnés</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Liste des produits -->
                <div class="col-lg-8">
                    <div class="products-section fade-in-up" style="animation-delay: 0.5s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-boxes"></i>Produits Disponibles</h3>
                            <span class="section-badge"><?php echo count($produits); ?> produits</span>
                        </div>
                        
                        <?php if (empty($produits)): ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h4>Aucun produit disponible</h4>
                                <p>Tous les produits sont en rupture de stock.</p>
                            </div>
                        <?php else: ?>
                            <div class="products-grid">
                                <?php foreach ($produits as $produit): ?>
                                    <div class="product-card">
                                        <div class="product-header">
                                            <div class="product-name"><?php echo htmlspecialchars($produit['nom']); ?></div>
                                            <?php if (in_array($stock_column, $table_columns['produits'])): ?>
                                            <div class="product-stock">
                                                <?php echo $produit['stock']; ?> dispo
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-price">
                                            €<?php echo number_format($produit['prix'], 2); ?>
                                        </div>
                                        <form method="POST" class="product-form">
                                            <input type="hidden" name="id_produit" value="<?php echo $produit['id_produit']; ?>">
                                            <input type="number" 
                                                   name="quantite" 
                                                   value="1" 
                                                   min="1" 
                                                   max="<?php echo $produit['stock']; ?>"
                                                   class="quantity-input"
                                                   required>
                                            <button type="submit" name="ajouter_au_panier" class="add-to-cart-btn">
                                                <i class="fas fa-cart-plus me-2"></i>Ajouter
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Panier -->
                <div class="col-lg-4">
                    <div class="cart-section fade-in-up" style="animation-delay: 0.6s;">
                        <div class="cart-header">
                            <h3 class="section-title"><i class="fas fa-shopping-cart"></i>Panier</h3>
                            <?php if (!empty($panier)): ?>
                                <form method="POST" class="d-inline">
                                    <button type="submit" name="vider_panier" class="clear-cart-btn" onclick="return confirm('Vider tout le panier?')">
                                        <i class="fas fa-trash me-2"></i>Vider
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($panier)): ?>
                            <div class="empty-state">
                                <i class="fas fa-shopping-cart"></i>
                                <h4>Panier vide</h4>
                                <p>Ajoutez des produits depuis la liste</p>
                            </div>
                        <?php else: ?>
                            <div class="cart-items">
                                <?php foreach ($panier as $id_produit => $item): ?>
                                    <div class="cart-item">
                                        <div class="item-info">
                                            <div class="item-name"><?php echo htmlspecialchars($item['nom']); ?></div>
                                            <div class="item-price">€<?php echo number_format($item['prix'], 2); ?> / unité</div>
                                        </div>
                                        <div class="item-actions">
                                            <form method="POST" class="d-flex align-items-center gap-2">
                                                <input type="hidden" name="id_produit" value="<?php echo $id_produit; ?>">
                                                <input type="number" 
                                                       name="quantite" 
                                                       value="<?php echo $item['quantite']; ?>" 
                                                       min="1" 
                                                       max="<?php echo $item['stock_disponible']; ?>"
                                                       class="item-quantity"
                                                       onchange="this.form.submit()">
                                                <button type="submit" name="modifier_quantite" class="update-btn" title="Modifier">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="id_produit" value="<?php echo $id_produit; ?>">
                                                <button type="submit" name="supprimer_du_panier" class="remove-btn" title="Supprimer">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Total et validation -->
                            <div class="cart-total">
                                <div class="total-amount">€<?php echo number_format($total_panier, 2); ?></div>
                                <div class="total-label">Total de la commande</div>
                                <div class="total-items"><?php echo $nombre_articles; ?> article(s)</div>
                            </div>
                            
                            <form method="POST">
                                <button type="submit" 
                                        name="finaliser_commande" 
                                        class="checkout-btn"
                                        onclick="return confirm('Confirmer la création de cette commande?')">
                                    <i class="fas fa-check me-2"></i>Finaliser la Commande
                                </button>
                            </form>
                            
                            <div class="text-center">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Les stocks seront automatiquement mis à jour
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Instructions -->
                    <div class="products-section mt-4 fade-in-up" style="animation-delay: 0.7s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-info-circle"></i>Instructions</h3>
                        </div>
                        <div class="small">
                            <ol class="mb-0">
                                <li class="mb-2">Sélectionnez les produits dans la liste</li>
                                <li class="mb-2">Ajoutez-les au panier avec les quantités souhaitées</li>
                                <li class="mb-2">Vérifiez le récapitulatif dans le panier</li>
                                <li>Finalisez la commande quand vous êtes prêt</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Fonction de déconnexion
        function logout() {
            Swal.fire({
                title: 'Déconnexion',
                text: 'Êtes-vous sûr de vouloir vous déconnecter ?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Oui, déconnecter',
                cancelButtonText: 'Annuler',
                background: 'rgba(255, 255, 255, 0.95)',
                backdrop: 'rgba(67, 97, 238, 0.1)'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }

        // Auto-submit des quantités modifiées
        document.querySelectorAll('.item-quantity').forEach(input => {
            input.addEventListener('change', function() {
                if (this.form && this.name === 'quantite') {
                    this.form.submit();
                }
            });
        });

        // Empêcher la soumission du formulaire si quantité invalide
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const quantiteInput = this.querySelector('input[name="quantite"]');
                if (quantiteInput) {
                    const max = parseInt(quantiteInput.getAttribute('max'));
                    const valeur = parseInt(quantiteInput.value);
                    if (valeur > max) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Erreur',
                            text: 'Quantité supérieure au stock disponible! Stock max: ' + max,
                            icon: 'error',
                            confirmButtonColor: '#4361ee',
                            background: 'rgba(255, 255, 255, 0.95)'
                        });
                        quantiteInput.focus();
                    }
                }
            });
        });

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

        // Confirmation pour vider le panier
        document.querySelectorAll('button[name="vider_panier"]').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Êtes-vous sûr de vouloir vider tout le panier ?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
<?php
// Fermer les connexions
if (isset($stmt_produits)) $stmt_produits->close();
$conn->close();
?>