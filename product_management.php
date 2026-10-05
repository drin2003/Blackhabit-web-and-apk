<?php
// BLACKHABIT: Admin uses its own independent session.
session_name('BH_ADMIN_SESSION');
session_start();

if (!isset($_SESSION['role']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));
if ($role !== 'admin' && (int)$_SESSION['role'] !== 1) {
    header("Location: index.php");
    exit();
}

include "db.php";

$success_msg = "";
$error_msg = "";

if (isset($_GET['repaired']) && $_GET['repaired'] === '1') {
    $success_msg = 'All product formulas and supplies were regenerated from the current automatic templates.';
}

$categories = [
    'Coffee',
    'Non Coffee',
    'Milk Tea',
    'Milk Tea Creamcheese',
    'Fruit Tea',
    'Frappes',
    'Snacks',
    'Fries',
    'Combos',
    'Shawarma'
];

$size_categories = [
    'Milk Tea',
    'Fruit Tea',
    'Non Coffee',
    'Frappes'
];

$available_ingredients = [];

$ingredient_result = mysqli_query(
    $conn,
    "SELECT id, ingredient_name, unit
     FROM ingredients
     ORDER BY ingredient_name ASC"
);

if ($ingredient_result) {
    while ($ing = mysqli_fetch_assoc($ingredient_result)) {
        $available_ingredients[] = $ing;
    }
}

$available_supplies = [];

$supply_result = mysqli_query(
    $conn,
    "SELECT id, supply_name
     FROM supplies
     ORDER BY supply_name ASC"
);

if ($supply_result) {
    while ($supply = mysqli_fetch_assoc($supply_result)) {
        $available_supplies[] = $supply;
    }
}

function save_product_formula(
    $conn,
    $product_id,
    $ingredient_ids,
    $regular_qtys,
    $large_qtys
) {
    $product_id = (int)$product_id;

    $clean = [];
    $seen = [];

    foreach ($ingredient_ids as $index => $ingredient_id) {

        $ingredient_id = (int)$ingredient_id;

        $regular = isset($regular_qtys[$index])
            ? (float)$regular_qtys[$index]
            : 0;

        $large = isset($large_qtys[$index]) &&
                 $large_qtys[$index] !== ''
            ? (float)$large_qtys[$index]
            : $regular;

        if ($ingredient_id <= 0) {
            continue;
        }

        if ($regular <= 0) {
            throw new Exception(
                'Every selected ingredient must have a quantity greater than 0.'
            );
        }

        if ($large < 0) {
            throw new Exception(
                'Ingredient quantity cannot be negative.'
            );
        }

        if (isset($seen[$ingredient_id])) {
            throw new Exception(
                'The same ingredient cannot be added twice to one product.'
            );
        }

        $seen[$ingredient_id] = true;

        $clean[] = [
            $ingredient_id,
            $regular,
            $large
        ];
    }

    if (!$clean) {
        throw new Exception(
            'Please add at least one ingredient to the product formula.'
        );
    }

    $delete = $conn->prepare(
        "DELETE FROM recipes WHERE product_id = ?"
    );

    if (!$delete) {
        throw new Exception(
            'Unable to prepare formula reset: ' . $conn->error
        );
    }

    $delete->bind_param(
        'i',
        $product_id
    );

    if (!$delete->execute()) {
        $delete->close();

        throw new Exception(
            'Unable to reset the existing product formula.'
        );
    }

    $delete->close();

    $insert = $conn->prepare(
        "INSERT INTO recipes
        (
            product_id,
            ingredient_id,
            regular_qty,
            large_qty
        )
        VALUES (?, ?, ?, ?)"
    );

    if (!$insert) {
        throw new Exception(
            'Unable to prepare product formula: ' . $conn->error
        );
    }

    foreach ($clean as [$ingredient_id, $regular, $large]) {

        $insert->bind_param(
            'iidd',
            $product_id,
            $ingredient_id,
            $regular,
            $large
        );

        if (!$insert->execute()) {

            $error = $insert->error;

            $insert->close();

            throw new Exception(
                'Unable to save product formula: ' . $error
            );
        }
    }

    $insert->close();
}

function save_product_supplies(
    $conn,
    $product_id,
    $supply_ids,
    $regular_qtys,
    $large_qtys
) {
    $product_id = (int)$product_id;
    $clean = [];
    $seen = [];

    foreach ($supply_ids as $index => $supply_id_value) {
        $supply_id_value = (int)$supply_id_value;

        $regular = isset($regular_qtys[$index])
            ? (float)$regular_qtys[$index]
            : 0;

        $large = isset($large_qtys[$index]) && $large_qtys[$index] !== ''
            ? (float)$large_qtys[$index]
            : 0;

        if ($supply_id_value <= 0) {
            continue;
        }

        if ($regular < 0 || $large < 0) {
            throw new Exception(
                'Supply quantity cannot be negative.'
            );
        }

        if ($regular == 0 && $large == 0) {
            continue;
        }

        if (isset($seen[$supply_id_value])) {
            throw new Exception(
                'The same supply cannot be added twice to one product.'
            );
        }

        $seen[$supply_id_value] = true;

        $clean[] = [
            $supply_id_value,
            $regular,
            $large
        ];
    }

    if (!$clean) {
        throw new Exception(
            'Please add at least one supply to the product.'
        );
    }

    $delete = $conn->prepare(
        "DELETE FROM product_supplies WHERE product_id = ?"
    );

    if (!$delete) {
        throw new Exception(
            'Unable to prepare supply reset: ' . $conn->error
        );
    }

    $delete->bind_param('i', $product_id);

    if (!$delete->execute()) {
        $delete->close();

        throw new Exception(
            'Unable to reset the existing product supplies.'
        );
    }

    $delete->close();

    $insert = $conn->prepare(
        "INSERT INTO product_supplies
        (
            product_id,
            supply_id,
            regular_qty,
            large_qty
        )
        VALUES (?, ?, ?, ?)"
    );

    if (!$insert) {
        throw new Exception(
            'Unable to prepare product supply insert: ' . $conn->error
        );
    }

    foreach ($clean as [$supply_id_value, $regular, $large]) {
        $insert->bind_param(
            'iidd',
            $product_id,
            $supply_id_value,
            $regular,
            $large
        );

        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();

            throw new Exception(
                'Unable to save product supplies: ' . $error
            );
        }
    }

    $insert->close();
}

function normalize_ingredient_name($name) {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', '', (string)$name)));
}

function get_ingredient_id_map($conn) {
    $map = [];
    $result = mysqli_query($conn, "SELECT id, ingredient_name FROM ingredients");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = normalize_ingredient_name($row['ingredient_name']);
            if ($key !== '') {
                $map[$key] = (int)$row['id'];
            }
        }
    }
    return $map;
}

function get_ingredient_unit_map($conn) {
    $map = [];
    $result = mysqli_query($conn, "SELECT id, unit FROM ingredients");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $map[(int)$row['id']] = strtolower(trim((string)$row['unit']));
        }
    }
    return $map;
}

// Automatic templates use base quantities: grams for solids/powders,
// milliliters for liquids, and pieces for whole items. Convert to the
// unit actually stored for each ingredient.
function convert_auto_formula_quantity($ingredient_id, $quantity, $unit_map) {
    $quantity = (float)$quantity;
    $unit = strtolower(trim((string)($unit_map[(int)$ingredient_id] ?? '')));

    if ($quantity <= 0 || $unit === '') {
        return $quantity;
    }

    if (in_array($unit, ['kg', 'kilogram', 'kilograms'], true)) {
        return $quantity / 1000;
    }

    if (in_array($unit, ['l', 'liter', 'liters', 'litre', 'litres'], true)) {
        return $quantity / 1000;
    }

    return $quantity;
}

function ingredient_id($map, $name) {
    $key = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', $name)));
    if (isset($map[$key])) {
        return $map[$key];
    }

    // Aliases keep the automatic templates compatible with the current
    // ingredient master list. The template names can be legacy names,
    // while the database contains the new standardized names.
    $aliases = [
        'milkteacups' => ['milkteacup', 'milk teacups', 'milk teacup'],
        'widemilkteastraw' => ['milkteastraw', 'wide milkteastraw', 'milkteawidestraw', 'milk tea wide straw'],
        'plasticcup' => ['plasticcups'],
        'plasticstraw' => ['plasticstraws'],
        '12ozplasticcup' => ['12ozcup'],
        '16ozplasticcup' => ['16ozcup'],
        '22ozplasticcup' => ['22ozcup'],

        // Current ingredient names / old template names
        'sugarsyrup' => ['sugar'],
        'brownsugarsyrup' => ['brownsugar'],
        'biscoffspread' => ['biscoffsauce'],
        'chocolatesauce' => ['chocolatesyrup'],
        'cookiescream powder' => ['cookiecrumbs'],
        'cookiescreampowder' => ['cookiecrumbs'],
        'wintermelonpowder' => ['wintermelonsyrup'],
        'saltedcaramelpowder' => ['saltedcaramelsyrup'],
        'frozenfries' => ['frenchfries'],
        'cookedrice' => ['rice'],
        'teabase' => ['fruitteabase'],
        'matchaberrysyrup' => ['matchapowder'],
        'melonsyrup' => ['melonpowder'],
        'pandansyrup' => ['pandanpowder'],
        'chocolatechips' => ['javachips'],
        'oreocookies' => ['oreocrumbs'],
        'ubepowder' => ['ubeflavor'],
        'coffee' => ['coffeebeans'],
        'cheesepowder' => ['creamcheese'],
        'blackpearl' => ['blackpearl'],
        'mixedberrysyrup' => ['mixedberriessyrup'],
        'whippingcream' => ['whippedcream'],
        'matchaberrysyrup' => ['matchapowder'],
        'taro' => ['taropowder'],
        'oreocookies' => ['oreocrumbs']
    ];

    foreach (($aliases[$key] ?? []) as $alias) {
        $aliasKey = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', $alias)));
        if (isset($map[$aliasKey])) {
            return $map[$aliasKey];
        }
    }

    return 0;
}

function add_auto_ingredient(&$rows, $map, $name, $regular, $large = null, $unit_map = []) {
    $id = ingredient_id($map, $name);
    if ($id > 0 && ($regular > 0 || (float)$large > 0)) {
        $large_value = $large === null ? (float)$regular : (float)$large;
        $rows[] = [
            $id,
            convert_auto_formula_quantity($id, $regular, $unit_map),
            convert_auto_formula_quantity($id, $large_value, $unit_map)
        ];
    }
}

// Prevent duplicate ingredient rows when aliases resolve to the same database ID.
function add_auto_ingredient_unique(&$rows, $map, $name, $regular, $large = null, $unit_map = []) {
    $id = ingredient_id($map, $name);
    if ($id <= 0) {
        return false;
    }
    foreach ($rows as $existing) {
        if ((int)$existing[0] === (int)$id) {
            return true;
        }
    }
    add_auto_ingredient($rows, $map, $name, $regular, $large, $unit_map);
    return true;
}

function add_matching_product_ingredient(&$rows, $map, $product_name, $regular, $large = null, $unit_map = []) {
    $candidates = [
        $product_name,
        $product_name . ' Syrup',
        $product_name . ' Powder',
        $product_name . ' Sauce',
        $product_name . ' Base'
    ];

    foreach ($candidates as $candidate) {
        $id = ingredient_id($map, $candidate);
        if ($id > 0) {
            $large_value = $large === null ? (float)$regular : (float)$large;
            $rows[] = [
                $id,
                convert_auto_formula_quantity($id, $regular, $unit_map),
                convert_auto_formula_quantity($id, $large_value, $unit_map)
            ];
            return true;
        }
    }

    return false;
}

function get_auto_formula($name, $category, $map, $unit_map = []) {
    $n = strtolower(trim(preg_replace('/\s+/', ' ', (string)$name)));
    $rows = [];

    // All recipe quantities are intended to use the unit stored in the
    // ingredients table. The standard for recipe quantities is:
    // g = solid/powder/beans/meat/rice; ml = liquid; pc = whole item.
    $add = function($ingredient, $regular, $large = null) use (&$rows, $map, $unit_map) {
        add_auto_ingredient_unique(
            $rows,
            $map,
            $ingredient,
            $regular,
            $large === null ? $regular : $large,
            $unit_map
        );
    };

    $isB1T1 = (
        strpos($n, 'b1t1') !== false ||
        strpos($n, 'buy 1 take 1') !== false ||
        strpos($n, 'buy1take1') !== false
    );

    if ($category === 'Coffee') {
        // Hot/iced coffee: regular 12oz, large 16oz.
        $add('Coffee Beans', 18, 24);

        if (strpos($n, 'biscoff') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Biscoff Spread', 20, 30);
            $add('Biscoff Crumbs', 5, 8);
        } elseif (strpos($n, 'brown sugar') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Brown Sugar Syrup', 25, 35);
        } elseif (strpos($n, 'butterscotch') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Butterscotch Syrup', 25, 35);
        } elseif (strpos($n, 'white chocolate') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('White Chocolate Sauce', 25, 35);
        } elseif (strpos($n, 'mocha') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Chocolate Syrup', 25, 35);
        } elseif (strpos($n, 'coffee jelly') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Coffee Jelly', 25, 35);
            $add('Sugar', 20, 30);
        } elseif (strpos($n, 'coffee crumble') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Coffee Crumble Powder', 15, 22);
            $add('Cookie Crumbs', 8, 12);
        } elseif (strpos($n, 'caramel') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Caramel Syrup', 25, 35);
        } elseif (strpos($n, 'seasalt') !== false || strpos($n, 'sea salt') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Sea Salt', 2, 3);
        } elseif (strpos($n, 'spanish') !== false) {
            $add('Fresh Milk', 120, 180);
            $add('Condensed Milk', 20, 30);
        } elseif (strpos($n, 'cappuccino') !== false) {
            $add('Fresh Milk', 120, 180);
        } elseif (strpos($n, 'americano') !== false) {
            // No milk/syrup for Americano.
        } else {
            $add('Fresh Milk', 120, 180);
            $add('Sugar', 20, 30);
        }
    }

    elseif ($category === 'Milk Tea') {
        $add('Fresh Milk', 120, 180);
        $add('Condensed Milk', 25, 35);
        $add('Sugar', 20, 30);

        $milkTeaMap = [
            'vanilla oreo' => ['Dark Oreo Powder', 15, 22],
            'dark oreo' => ['Dark Oreo Powder', 15, 22],
            'cookies & cream' => ['Cookie Crumbs', 15, 22],
            'okinawa' => ['Okinawa Powder', 15, 22],
            'matcha' => ['Matcha Powder', 8, 12],
            'dark chocolate' => ['Dark Chocolate Powder', 15, 22],
            'wintermelon' => ['Wintermelon Syrup', 15, 22],
            'taro' => ['Taro Powder', 15, 22],
            'red velvet' => ['Red Velvet Powder', 15, 22],
            'black forest' => ['Black Forest Powder', 15, 22],
            'coffee crumble' => ['Coffee Crumble Powder', 15, 22],
            'nutella' => ['Nutella', 20, 30],
            'cappuccino' => ['Cappuccino Powder', 15, 22],
            'mango pastillas' => ['Mango Pastillas Powder', 15, 22],
            'salted caramel' => ['Salted Caramel Syrup', 15, 22],
            'strawberry' => ['Strawberry Milk Tea Powder', 15, 22],
            'avocado' => ['Avocado Powder', 15, 22],
            'cheesecake' => ['Cheesecake Powder', 15, 22]
        ];

        $matched = false;
        foreach ($milkTeaMap as $productText => $formula) {
            if (strpos($n, $productText) !== false) {
                $add($formula[0], $formula[1], $formula[2]);
                $matched = true;

                if (in_array($productText, ['black forest', 'cheesecake'], true)) {
                    $add('Cream Cheese', 20, 30);
                }
                if ($productText === 'coffee crumble') {
                    $add('Coffee Crumble Powder', 15, 22);
                }
                if (in_array($productText, ['vanilla oreo', 'dark oreo', 'cookies & cream'], true)) {
                    $add('Cookie Crumbs', 8, 12);
                }
                break;
            }
        }

        if (!$matched) {
            add_matching_product_ingredient($rows, $map, $name, 15, 22, $unit_map);
        }
    }

    elseif ($category === 'Milk Tea Creamcheese' || $category === 'MilkTea Creamcheese') {
        $add('Fresh Milk', 120, 180);
        $add('Condensed Milk', 25, 35);
        $add('Cream Cheese', 25, 35);

        if (strpos($n, 'oreo') !== false || strpos($n, 'cookies') !== false) {
            $add('Oreo Crumbs', 15, 22);
            $add('Cookie Crumbs', 8, 12);
        } elseif (strpos($n, 'matcha') !== false) {
            $add('Matcha Powder', 8, 12);
        } elseif (strpos($n, 'taro') !== false) {
            $add('Taro Powder', 15, 22);
        } elseif (strpos($n, 'strawberry') !== false) {
            $add('Strawberry Milk Tea Powder', 20, 30);
        } elseif (strpos($n, 'mango') !== false) {
            $add('Mango Pastillas Powder', 20, 30);
        } elseif (strpos($n, 'coffee crumble') !== false) {
            $add('Coffee Crumble Powder', 15, 22);
        } elseif (strpos($n, 'cappuccino') !== false) {
            $add('Cappuccino Powder', 15, 22);
        } elseif (strpos($n, 'coffee') !== false) {
            $add('Coffee Beans', 15, 22);
        } elseif (strpos($n, 'chocolate') !== false || strpos($n, 'forest') !== false) {
            $add('Dark Chocolate Powder', 20, 30);
        } else {
            add_matching_product_ingredient($rows, $map, $name, 15, 22, $unit_map);
        }
    }

    elseif ($category === 'Fruit Tea') {
        $add('Fruit Tea Base', 120, 180);
        $add('Sugar', 20, 30);

        $fruitTeaMap = [
            'blueberry' => 'Blueberry Syrup',
            'blue lemonade' => 'Blue Lemonade Syrup',
            'green apple' => 'Green Apple Syrup',
            'lychee' => 'Lychee Syrup',
            'mix berries' => 'Mixed Berries Syrup',
            'mixed berries' => 'Mixed Berries Syrup',
            'passion fruit' => 'Passion Fruit Syrup',
            'strawberry' => 'Strawberry Syrup',
            'tropical' => 'Tropical Syrup'
        ];

        foreach ($fruitTeaMap as $productText => $ingredientName) {
            if (strpos($n, $productText) !== false) {
                $add($ingredientName, 25, 35);
                break;
            }
        }

        $add('Fruit Jelly', 15, 25);
    }

    elseif ($category === 'Non Coffee') {
        $add('Fresh Milk', 120, 180);
        $add('Condensed Milk', 25, 35);

        if (strpos($n, 'matchaberry') !== false) {
            $add('Matcha Powder', 8, 12);
            $add('Strawberry Powder', 15, 22);
        } elseif (strpos($n, 'matcha') !== false) {
            $add('Matcha Powder', 8, 12);
        } elseif (strpos($n, 'choco') !== false || strpos($n, 'chocolate') !== false) {
            $add('Chocolate Powder', 25, 35);
        } elseif (strpos($n, 'melon') !== false) {
            $add('Melon Powder', 20, 30);
        } elseif (strpos($n, 'strawberry') !== false) {
            $add('Strawberry Powder', 20, 30);
        } elseif (strpos($n, 'pandan') !== false) {
            $add('Pandan Powder', 20, 30);
        } elseif (strpos($n, 'brown sugar cheesecake') !== false) {
            $add('Brown Sugar Cheesecake Base', 25, 35);
            $add('Cheesecake Powder', 15, 22);
        } else {
            add_matching_product_ingredient($rows, $map, $name, 20, 30, $unit_map);
        }
    }

    elseif ($category === 'Frappes') {
        $add('Fresh Milk', 100, 150);
        $add('Frappe Base', 30, 45);
        $add('Frappe Ice', 180, 250);
        $add('Whipped Cream', 20, 30);

        if (strpos($n, 'coffee jelly') !== false) {
            $add('Coffee Beans', 15, 22);
            $add('Coffee Jelly', 25, 35);
        } elseif (strpos($n, 'coffee crumble') !== false) {
            $add('Coffee Frappe Base', 30, 45);
            $add('Coffee Crumble Powder', 15, 22);
            $add('Cookie Crumbs', 8, 12);
        } elseif (strpos($n, 'choco deluxe') !== false || strpos($n, 'chocolate') !== false) {
            $add('Chocolate Frappe Base', 30, 45);
            $add('Chocolate Powder', 15, 22);
        } elseif (strpos($n, 'peanut butter') !== false) {
            $add('Peanut Butter', 20, 30);
            $add('Cookie Crumbs', 10, 15);
        } elseif (strpos($n, 'cookies & cream') !== false || strpos($n, 'oreo') !== false) {
            $add('Oreo Crumbs', 15, 22);
            $add('Cookie Crumbs', 8, 12);
        } elseif (strpos($n, 'oreo strawberry') !== false) {
            $add('Oreo Crumbs', 15, 22);
            $add('Strawberry Syrup', 20, 30);
        } elseif (strpos($n, 'avocado') !== false) {
            $add('Avocado Flavor', 15, 22);
        } elseif (strpos($n, 'ube') !== false || strpos($n, 'quezo') !== false) {
            $add('Ube Flavor', 15, 22);
            $add('Quezo Flavor', 15, 22);
        } elseif (strpos($n, 'pandan') !== false) {
            $add('Pandan Flavor', 15, 22);
        } elseif (strpos($n, 'java chips') !== false) {
            $add('Java Chips', 15, 22);
        } elseif (strpos($n, 'mango graham') !== false) {
            $add('Mango Syrup', 20, 30);
            $add('Graham Crumbs', 15, 22);
        } else {
            add_matching_product_ingredient($rows, $map, $name, 15, 22, $unit_map);
        }
    }

    elseif ($category === 'Snacks') {
        $friesQty = strpos($n, 'barkada') !== false ? 250 : 150;
        $add('French Fries', $friesQty, $friesQty);
        $add('Fries Seasoning', 5, 5);
        if (strpos($n, 'overload') !== false) {
            $add('Cheese Sauce', 25, 25);
            $add('Mayonnaise', 10, 10);
            $add('Ketchup', 10, 10);
        }
    }

    elseif ($category === 'Fries') {
        $friesQty = strpos($n, 'barkada') !== false ? 250 : 150;
        $add('French Fries', $friesQty, $friesQty);
        $add('Fries Seasoning', 5, 5);
        if (strpos($n, 'overload') !== false) {
            $add('Cheese Sauce', 25, 25);
            $add('Mayonnaise', 10, 10);
            $add('Ketchup', 10, 10);
        }
    }

    elseif ($category === 'Combos') {
        // Combos contain a food item plus a drink. Build the food portion
        // from the product name, then add the named drink portion.
        if (strpos($n, 'shawarma rice') !== false) {
            $mult = $isB1T1 ? 2 : 1;
            $add('Shawarma Meat', 80 * $mult, 80 * $mult);
            $add('Rice', 200 * $mult, 200 * $mult);
            $add('Lettuce', 20 * $mult, 20 * $mult);
            $add('Tomato', 20 * $mult, 20 * $mult);
            $add('Onion', 10 * $mult, 10 * $mult);
            $add('Garlic Sauce', 20 * $mult, 20 * $mult);
        } elseif (strpos($n, 'shawarma pita') !== false || strpos($n, 'shawarma') !== false) {
            $mult = $isB1T1 ? 2 : 1;
            $add('Shawarma Meat', 80 * $mult, 80 * $mult);
            $add('Pita Bread', 1 * $mult, 1 * $mult);
            $add('Lettuce', 20 * $mult, 20 * $mult);
            $add('Tomato', 20 * $mult, 20 * $mult);
            $add('Onion', 10 * $mult, 10 * $mult);
            $add('Garlic Sauce', 20 * $mult, 20 * $mult);
            if (strpos($n, 'cheese') !== false) {
                $add('Cheese', 1 * $mult, 1 * $mult);
            }
        } elseif (strpos($n, 'burger') !== false) {
            $mult = $isB1T1 ? 2 : 1;
            $add('Burger Bun', 1 * $mult, 1 * $mult);
            $add('Burger Patty', 1 * $mult, 1 * $mult);
            $add('Burger Sauce', 15 * $mult, 15 * $mult);
            if (strpos($n, 'cheese') !== false) {
                $add('Burger Cheese', 1 * $mult, 1 * $mult);
            }
        } elseif (strpos($n, 'fries') !== false) {
            $mult = $isB1T1 ? 2 : 1;
            $add('French Fries', 150 * $mult, 150 * $mult);
            $add('Fries Seasoning', 5 * $mult, 5 * $mult);
            $add('Cheese Sauce', 25 * $mult, 25 * $mult);
        }

        if (strpos($n, 'fruit tea') !== false || strpos($n, 'fruitea') !== false) {
            $add('Fruit Tea Base', 120, 120);
            $add('Sugar', 20, 20);
            $add('Fruit Jelly', 15, 15);
        } elseif (strpos($n, 'milk tea') !== false || strpos($n, 'milktea') !== false) {
            $add('Fresh Milk', 120, 120);
            $add('Condensed Milk', 25, 25);
            $add('Sugar', 20, 20);
        }
    }

    elseif ($category === 'Shawarma') {
        $mult = $isB1T1 ? 2 : 1;

        // Shawarma Burger is a separate product family from pita/rice.
        if (strpos($n, 'burger') !== false) {
            $add('Burger Bun', 1 * $mult, 1 * $mult);
            $add('Burger Patty', 1 * $mult, 1 * $mult);
            $add('Burger Sauce', 15 * $mult, 15 * $mult);
            if (strpos($n, 'cheese') !== false) {
                $add('Burger Cheese', 1 * $mult, 1 * $mult);
            }
        } elseif (strpos($n, 'rice') !== false) {
            $add('Shawarma Meat', 80 * $mult, 80 * $mult);
            $add('Rice', 200 * $mult, 200 * $mult);
            $add('Lettuce', 20 * $mult, 20 * $mult);
            $add('Tomato', 20 * $mult, 20 * $mult);
            $add('Onion', 10 * $mult, 10 * $mult);
            $add('Garlic Sauce', 20 * $mult, 20 * $mult);
        } else {
            $add('Shawarma Meat', 80 * $mult, 80 * $mult);
            $add('Pita Bread', 1 * $mult, 1 * $mult);
            $add('Lettuce', 20 * $mult, 20 * $mult);
            $add('Tomato', 20 * $mult, 20 * $mult);
            $add('Onion', 10 * $mult, 10 * $mult);
            $add('Garlic Sauce', 20 * $mult, 20 * $mult);

            if (strpos($n, 'cheese') !== false) {
                $add('Cheese', 1 * $mult, 1 * $mult);
            }
        }
    }

    // Always display generated ingredients alphabetically by ingredient name.
    usort($rows, function ($a, $b) use ($map) {
        $nameA = '';
        $nameB = '';
        foreach ($map as $key => $id) {
            if ((int)$id === (int)$a[0]) { $nameA = $key; }
            if ((int)$id === (int)$b[0]) { $nameB = $key; }
        }
        return strcasecmp($nameA, $nameB);
    });

    // Always keep automatically generated ingredients in A-Z order.
    usort($rows, function ($a, $b) use ($map) {
        $nameA = '';
        $nameB = '';

        foreach ($map as $key => $id) {
            if ((int)$id === (int)$a[0]) {
                $nameA = $key;
            }
            if ((int)$id === (int)$b[0]) {
                $nameB = $key;
            }
        }

        return strcasecmp($nameA, $nameB);
    });

    return $rows;
}

function normalize_supply_name($name) {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', '', (string)$name)));
}

function get_supply_id_map($conn) {
    $map = [];
    $result = mysqli_query($conn, "SELECT id, supply_name FROM supplies");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = normalize_supply_name($row['supply_name']);
            if ($key !== '') {
                $map[$key] = (int)$row['id'];
            }
        }
    }
    return $map;
}

function supply_id($map, $name) {
    $key = normalize_supply_name($name);

    if (isset($map[$key])) {
        return $map[$key];
    }

    // Only aliases that correspond to the current standardized supply master.
    $aliases = [
        'widemilkteastraw' => ['milkteastraw', 'wide milkteastraws', 'milkteawidestraw'],
        'frappestraw' => ['frappewidestraw', 'frappestraws'],
        'plasticstraw' => ['regularplasticstraw', 'plasticstraws'],
        '12ozplasticcup' => ['12ozcup', '12ozcups'],
        '12ozcuplid' => ['12ozlid', '12ozlids'],
        '16ozplasticcup' => ['16ozcup', '16ozcups'],
        '16ozcuplid' => ['16ozlid', '16ozlids'],
        '22ozplasticcup' => ['22ozcup', '22ozcups'],
        '22ozcuplid' => ['22ozlid', '22ozlids'],
        'friespaperboxcup' => ['friespaperbox', 'friespapercup', 'friespaperboxcup'],
        'burgerwrapper' => ['burgerwrappers', 'foodpaper', 'shawarmaburgerwrapper'],
        'smallplasticbag' => ['smallplasticbags'],
        'foodtakeoutbox' => ['foodtakeoutboxes'],
        'shawarmapitawrapper' => ['shawarmapitawrappers'],
        'disposablespoon' => ['disposablespoons'],
        'burgerbox' => ['burgerboxes']
    ];

    foreach (($aliases[$key] ?? []) as $alias) {
        $aliasKey = normalize_supply_name($alias);
        if (isset($map[$aliasKey])) {
            return $map[$aliasKey];
        }
    }

    return 0;
}

function add_auto_supply(&$rows, $map, $name, $regular, $large = null) {
    $id = supply_id($map, $name);
    if ($id > 0 && ($regular > 0 || (float)$large > 0)) {
        $rows[] = [$id, (float)$regular, $large === null ? (float)$regular : (float)$large];
    }
}

function get_auto_supply_formula($name, $category, $map) {
    $n = strtolower(trim(preg_replace('/\s+/', ' ', (string)$name)));
    $category = strtolower(trim(preg_replace('/\s+/', ' ', (string)$category)));
    $rows = [];
    $multiplier = (strpos($n, 'b1t1') !== false || strpos($n, 'buy 1 take 1') !== false || strpos($n, 'buy1take1') !== false) ? 2 : 1;

    if (in_array($category, ['coffee', 'non coffee', 'fruit tea'], true)) {
        add_auto_supply($rows, $map, '12oz Plastic Cup', 1, 0);
        add_auto_supply($rows, $map, '12oz Cup Lid', 1, 0);
        add_auto_supply($rows, $map, '16oz Plastic Cup', 0, 1);
        add_auto_supply($rows, $map, '16oz Cup Lid', 0, 1);
        add_auto_supply($rows, $map, 'Plastic Straw', 1, 1);
    } elseif (in_array($category, ['milk tea creamcheese', 'milktea creamcheese'], true)) {
        // Milk Tea Creamcheese has regular size only.
        add_auto_supply($rows, $map, '16oz Plastic Cup', 1, 0);
        add_auto_supply($rows, $map, '16oz Cup Lid', 1, 0);
        add_auto_supply($rows, $map, 'Wide Milk Tea Straw', 1, 1);
    } elseif ($category === 'milk tea') {
        // Regular Milk Tea keeps both regular and large supplies.
        add_auto_supply($rows, $map, '16oz Plastic Cup', 1, 0);
        add_auto_supply($rows, $map, '16oz Cup Lid', 1, 0);
        add_auto_supply($rows, $map, '22oz Plastic Cup', 0, 1);
        add_auto_supply($rows, $map, '22oz Cup Lid', 0, 1);
        add_auto_supply($rows, $map, 'Wide Milk Tea Straw', 1, 1);
    } elseif ($category === 'frappes') {
        add_auto_supply($rows, $map, '16oz Plastic Cup', 1, 0);
        add_auto_supply($rows, $map, '16oz Cup Lid', 1, 0);
        add_auto_supply($rows, $map, '22oz Plastic Cup', 0, 1);
        add_auto_supply($rows, $map, '22oz Cup Lid', 0, 1);
        add_auto_supply($rows, $map, 'Frappe Straw', 1, 1);
    } elseif ($category === 'snacks' || $category === 'fries') {
        add_auto_supply($rows, $map, 'Fries Paper Box', 1, 1);
    } elseif ($category === 'shawarma') {
        if (strpos($n, 'rice') !== false) {
            add_auto_supply($rows, $map, 'Food Takeout Box', 1, 1);
            add_auto_supply($rows, $map, 'Disposable Spoon', 1, 1);
        } else {
            add_auto_supply($rows, $map, 'Shawarma Pita Wrapper', 1, 1);
            add_auto_supply($rows, $map, 'Small Plastic Bag', 1, 1);
        }
    } elseif ($category === 'combos') {
        if (strpos($n, 'shawarma') !== false) {
            if (strpos($n, 'rice') !== false) {
                add_auto_supply($rows, $map, 'Food Takeout Box', $multiplier, $multiplier);
                add_auto_supply($rows, $map, 'Disposable Spoon', $multiplier, $multiplier);
            } else {
                add_auto_supply($rows, $map, 'Shawarma Pita Wrapper', $multiplier, $multiplier);
                add_auto_supply($rows, $map, 'Small Plastic Bag', $multiplier, $multiplier);
            }
        } elseif (strpos($n, 'burger') !== false) {
            add_auto_supply($rows, $map, 'Burger Box', $multiplier, $multiplier);
            add_auto_supply($rows, $map, 'Burger Wrapper', $multiplier, $multiplier);
        } elseif (strpos($n, 'fries') !== false) {
            if (strpos($n, 'barkada') !== false) {
                add_auto_supply($rows, $map, 'Fries Paper Box', $multiplier, $multiplier);
            } else {
                add_auto_supply($rows, $map, 'Fries Paper Box', $multiplier, $multiplier);
            }
        }

        if (strpos($n, 'fruit tea') !== false || strpos($n, 'fruittea') !== false) {
            add_auto_supply($rows, $map, '12oz Plastic Cup', $multiplier, 0);
            add_auto_supply($rows, $map, '12oz Cup Lid', $multiplier, 0);
            add_auto_supply($rows, $map, 'Plastic Straw', $multiplier, $multiplier);
        } elseif (strpos($n, 'milk tea') !== false || strpos($n, 'milktea') !== false) {
            add_auto_supply($rows, $map, '16oz Plastic Cup', $multiplier, 0);
            add_auto_supply($rows, $map, '16oz Cup Lid', $multiplier, 0);
            add_auto_supply($rows, $map, 'Wide Milk Tea Straw', $multiplier, $multiplier);
        } else {
            // Every combo gets a regular straw for its included drink.
            add_auto_supply($rows, $map, 'Plastic Straw', $multiplier, $multiplier);
        }
    }

    // Always display generated supplies alphabetically by supply name.
    usort($rows, function ($a, $b) use ($map) {
        $nameA = '';
        $nameB = '';
        foreach ($map as $key => $id) {
            if ((int)$id === (int)$a[0]) { $nameA = $key; }
            if ((int)$id === (int)$b[0]) { $nameB = $key; }
        }
        return strcasecmp($nameA, $nameB);
    });

    // Always keep automatically generated supplies in A-Z order.
    usort($rows, function ($a, $b) use ($map) {
        $nameA = '';
        $nameB = '';

        foreach ($map as $key => $id) {
            if ((int)$id === (int)$a[0]) {
                $nameA = $key;
            }
            if ((int)$id === (int)$b[0]) {
                $nameB = $key;
            }
        }

        return strcasecmp($nameA, $nameB);
    });

    return $rows;
}

function save_auto_product_supplies($conn, $product_id, $product_name, $category) {
    $map = get_supply_id_map($conn);

    if (!$map) {
        throw new Exception('No supplies were found in the supplies table.');
    }

    $rows = get_auto_supply_formula($product_name, $category, $map);

    if (!$rows) {
        throw new Exception(
            'No automatic supply template is available for "' . $product_name . '" (' . $category . ').'
        );
    }

    $delete = $conn->prepare("DELETE FROM product_supplies WHERE product_id = ?");
    if (!$delete) {
        throw new Exception('Unable to prepare automatic supply reset: ' . $conn->error);
    }

    $delete->bind_param('i', $product_id);

    if (!$delete->execute()) {
        $error = $delete->error;
        $delete->close();
        throw new Exception('Unable to reset automatic supplies: ' . $error);
    }

    $delete->close();

    $insert = $conn->prepare(
        "INSERT INTO product_supplies
            (product_id, supply_id, regular_qty, large_qty)
         VALUES (?, ?, ?, ?)"
    );

    if (!$insert) {
        throw new Exception('Unable to prepare automatic supply insert: ' . $conn->error);
    }

    foreach ($rows as [$supply_id_value, $regular, $large]) {
        $insert->bind_param(
            'iidd',
            $product_id,
            $supply_id_value,
            $regular,
            $large
        );

        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new Exception('Unable to save automatic supplies: ' . $error);
        }
    }

    $insert->close();
}

function ensure_all_product_supplies($conn) {
    $result = mysqli_query(
        $conn,
        "SELECT id, name, category FROM products ORDER BY id ASC"
    );

    if (!$result) {
        return;
    }

    while ($product = mysqli_fetch_assoc($result)) {
        $product_id = (int)$product['id'];

        $count_result = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS total
             FROM product_supplies
             WHERE product_id = $product_id"
        );

        $count_row = $count_result
            ? mysqli_fetch_assoc($count_result)
            : null;

        $count = (int)($count_row['total'] ?? 0);

        if ($count > 0) {
            continue;
        }

        try {
            save_auto_product_supplies(
                $conn,
                $product_id,
                $product['name'],
                $product['category']
            );
        } catch (Exception $e) {
            $_SESSION['supply_error'] = $e->getMessage();
        }
    }
}

function save_auto_product_formula($conn, $product_id, $product_name, $category) {
    $map = get_ingredient_id_map($conn);
    $unit_map = get_ingredient_unit_map($conn);
    $rows = get_auto_formula($product_name, $category, $map, $unit_map);

    if (!$rows) {
        throw new Exception(
            'No automatic formula template is available for "' . $product_name . '".'
        );
    }

    $delete = $conn->prepare("DELETE FROM recipes WHERE product_id = ?");
    if (!$delete) {
        throw new Exception('Unable to prepare automatic formula reset: ' . $conn->error);
    }
    $delete->bind_param('i', $product_id);
    if (!$delete->execute()) {
        $error = $delete->error;
        $delete->close();
        throw new Exception('Unable to reset automatic formula: ' . $error);
    }
    $delete->close();

    $insert = $conn->prepare(
        "INSERT INTO recipes (product_id, ingredient_id, regular_qty, large_qty)
         VALUES (?, ?, ?, ?)"
    );
    if (!$insert) {
        throw new Exception('Unable to prepare automatic formula insert: ' . $conn->error);
    }

    foreach ($rows as [$ingredient_id, $regular, $large]) {
        $insert->bind_param('iidd', $product_id, $ingredient_id, $regular, $large);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new Exception('Unable to save automatic formula: ' . $error);
        }
    }
    $insert->close();
}

function ensure_all_product_formulas($conn) {
    $result = mysqli_query(
        $conn,
        "SELECT id, name, category FROM products ORDER BY id ASC"
    );

    if (!$result) {
        return;
    }

    while ($product = mysqli_fetch_assoc($result)) {
        $product_id = (int)$product['id'];
        $product_name = $product['name'];
        $category = $product['category'];

        try {
            $recipeCountResult = mysqli_query(
                $conn,
                "SELECT COUNT(*) AS total FROM recipes WHERE product_id = $product_id"
            );
            $recipeCountRow = $recipeCountResult ? mysqli_fetch_assoc($recipeCountResult) : null;
            $recipeCount = (int)($recipeCountRow['total'] ?? 0);

            if ($recipeCount === 0) {
                save_auto_product_formula($conn, $product_id, $product_name, $category);
            }
        } catch (Exception $e) {
            $_SESSION['formula_error'] = $e->getMessage();
        }
    }
}

if (isset($_GET['delete_id'])) {

    $del_id = intval($_GET['delete_id']);

    if ($del_id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM products WHERE id = ?"
        );

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $del_id
            );

            if ($stmt->execute()) {

                $success_msg =
                    "Product deleted successfully.";

            } else {

                $error_msg =
                    "Error deleting product: " .
                    $stmt->error;
            }

            $stmt->close();

        } else {

            $error_msg =
                "Delete preparation failed: " .
                $conn->error;
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_product'])
) {

    $id = intval(
        $_POST['id'] ?? 0
    );

    $name = trim(
        $_POST['name'] ?? ''
    );

    $category = trim(
        $_POST['category'] ?? ''
    );

    $regular_price = floatval(
        $_POST['regular_price'] ?? 0
    );

    $large_price = floatval(
        $_POST['large_price'] ?? 0
    );

    $stock = intval(
        $_POST['stock'] ?? 0
    );

    if (!in_array($category, $categories, true) && str_replace(' ', '', strtolower($category)) !== 'milkteacreamcheese') {

        $error_msg =
            "Invalid product category.";

    } else {

        $hasSizes = in_array($category, $size_categories, true);

        if (!$hasSizes) {
            $large_price = 0;
        }

        if (
            $id <= 0 ||
            $name === '' ||
            $category === '' ||
            $regular_price < 0 ||
            ($hasSizes && $large_price <= 0) ||
            $stock < 0
        ) {

            $error_msg =
                "Please enter valid product information.";

        } else {

            $price = $regular_price;

            $stmt = $conn->prepare(
                "UPDATE products
                 SET
                    name = ?,
                    category = ?,
                    price = ?,
                    regular_price = ?,
                    large_price = ?,
                    stock = ?
                 WHERE id = ?"
            );

            if ($stmt) {

                $stmt->bind_param(
                    "ssdddii",
                    $name,
                    $category,
                    $price,
                    $regular_price,
                    $large_price,
                    $stock,
                    $id
                );

                try {

                    mysqli_begin_transaction($conn);

                    if (!$stmt->execute()) {
                        throw new Exception(
                            "Update failed: " .
                            $stmt->error
                        );
                    }

                    // Save the edited ingredient formula when formula rows were submitted.
                    // If no formula rows were submitted, create the automatic formula only
                    // when this product does not have one yet.
                    $recipeIngredientIds = $_POST['recipe_ingredient_id'] ?? [];
                    $recipeRegularQtys = $_POST['recipe_regular_qty'] ?? [];
                    $recipeLargeQtys = $_POST['recipe_large_qty'] ?? [];

                    if (
                        is_array($recipeIngredientIds) &&
                        count($recipeIngredientIds) > 0
                    ) {
                        save_product_formula(
                            $conn,
                            $id,
                            $recipeIngredientIds,
                            $recipeRegularQtys,
                            $recipeLargeQtys
                        );
                    } else {
                        $recipeCheck = mysqli_query(
                            $conn,
                            "SELECT COUNT(*) AS total FROM recipes WHERE product_id = " . (int)$id
                        );
                        $recipeCheckRow = $recipeCheck ? mysqli_fetch_assoc($recipeCheck) : null;
                        $recipeCount = (int)($recipeCheckRow['total'] ?? 0);

                        if ($recipeCount === 0) {
                            save_auto_product_formula($conn, $id, $name, $category);
                        }
                    }

                    // Save edited supplies when supplied by the Edit Product form.
                    // If no supply rows were submitted, regenerate the automatic
                    // supply template for the edited product.
                    $supplyIds = $_POST['product_supply_id'] ?? [];
                    $supplyRegularQtys = $_POST['product_supply_regular_qty'] ?? [];
                    $supplyLargeQtys = $_POST['product_supply_large_qty'] ?? [];

                    if (
                        is_array($supplyIds) &&
                        count($supplyIds) > 0
                    ) {
                        save_product_supplies(
                            $conn,
                            $id,
                            $supplyIds,
                            $supplyRegularQtys,
                            $supplyLargeQtys
                        );
                    } else {
                        save_auto_product_supplies(
                            $conn,
                            $id,
                            $name,
                            $category
                        );
                    }

                    mysqli_commit($conn);

                    header(
                        "Location: product_management.php?updated=1"
                    );

                    exit();

                } catch (Exception $e) {

                    mysqli_rollback($conn);

                    $error_msg =
                        $e->getMessage();
                }

                $stmt->close();

            } else {

                $error_msg =
                    "Update preparation failed: " .
                    $conn->error;
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_product'])
) {

    $name = trim(
        $_POST['name'] ?? ''
    );

    $category = trim(
        $_POST['category'] ?? ''
    );

    $regular_price = floatval(
        $_POST['regular_price'] ?? 0
    );

    $large_price = floatval(
        $_POST['large_price'] ?? 0
    );

    $stock = intval(
        $_POST['stock'] ?? 0
    );

    $image_db_path = "";

    if (!in_array($category, $categories, true)) {

        $error_msg =
            "Please select a valid product category.";

    } else {

        if (
            !in_array(
                $category,
                $size_categories,
                true
            )
        ) {
            $large_price = 0;
        }

        if (
            $name === '' ||
            $category === '' ||
            $regular_price <= 0 ||
            $stock < 0
        ) {

            $error_msg =
                "Please enter valid product information.";

        } else {

            if (
                isset($_FILES['product_image']) &&
                $_FILES['product_image']['error']
                !== UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $_FILES['product_image']['error']
                    !== UPLOAD_ERR_OK
                ) {

                    $error_msg =
                        "There was a problem uploading the image.";

                } else {

                    $file_ext = strtolower(
                        pathinfo(
                            $_FILES['product_image']['name'],
                            PATHINFO_EXTENSION
                        )
                    );

                    $allowed = [
                        'jpg',
                        'jpeg',
                        'png',
                        'webp'
                    ];

                    if (
                        !in_array(
                            $file_ext,
                            $allowed,
                            true
                        )
                    ) {

                        $error_msg =
                            "Invalid image type. Use JPG, JPEG, PNG, or WEBP.";

                    } else {

                        $api_images_dir =
                            rtrim(
                                $_SERVER['DOCUMENT_ROOT'],
                                '/\\'
                            )
                            . DIRECTORY_SEPARATOR
                            . 'blackhabit_api'
                            . DIRECTORY_SEPARATOR
                            . 'images'
                            . DIRECTORY_SEPARATOR;

                        if (!is_dir($api_images_dir)) {

                            if (
                                !mkdir(
                                    $api_images_dir,
                                    0777,
                                    true
                                )
                            ) {

                                $error_msg =
                                    "Unable to create blackhabit_api/images folder.";
                            }
                        }

                        if (empty($error_msg)) {

                            try {

                                $random_name =
                                    bin2hex(
                                        random_bytes(5)
                                    );

                            } catch (Exception $e) {

                                $random_name =
                                    uniqid();
                            }

                            $new_file_name =
                                "prod_"
                                . time()
                                . "_"
                                . $random_name
                                . "."
                                . $file_ext;

                            $target_file =
                                $api_images_dir .
                                $new_file_name;

                            if (
                                move_uploaded_file(
                                    $_FILES['product_image']['tmp_name'],
                                    $target_file
                                )
                            ) {

                                $image_db_path =
                                    $new_file_name;

                            } else {

                                $error_msg =
                                    "Failed to save the image to blackhabit_api/images/.";
                            }
                        }
                    }
                }
            }

            if (empty($error_msg)) {

                $price = $regular_price;

                $stmt = $conn->prepare(
                    "INSERT INTO products
                    (
                        name,
                        category,
                        price,
                        regular_price,
                        large_price,
                        stock,
                        image
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                if ($stmt) {

                    $stmt->bind_param(
                        "ssdddis",
                        $name,
                        $category,
                        $price,
                        $regular_price,
                        $large_price,
                        $stock,
                        $image_db_path
                    );

                    try {

                        mysqli_begin_transaction($conn);

                        if (!$stmt->execute()) {
                            throw new Exception(
                                "Database Error: " .
                                $stmt->error
                            );
                        }

                        $new_product_id =
                            $stmt->insert_id;

                        save_auto_product_formula(
                            $conn,
                            $new_product_id,
                            $name,
                            $category
                        );

                        save_auto_product_supplies(
                            $conn,
                            $new_product_id,
                            $name,
                            $category
                        );

                        mysqli_commit($conn);

                        $success_msg =
                            "Product and formula added successfully!";

                    } catch (Exception $e) {

                        mysqli_rollback($conn);

                        $error_msg =
                            $e->getMessage();
                    }

                    $stmt->close();

                } else {

                    $error_msg =
                        "Insert preparation failed: " .
                        $conn->error;
                }
            }
        }
    }
}

// One-time repair for existing automatically generated formulas/supplies.
// Use: product_management.php?repair_formulas=1
// This intentionally rebuilds all current product formulas from the templates above.
if (isset($_GET['repair_formulas']) && $_GET['repair_formulas'] === '1') {
    $repairResult = mysqli_query($conn, "SELECT id, name, category FROM products ORDER BY id ASC");

    if ($repairResult) {
        while ($product = mysqli_fetch_assoc($repairResult)) {
            try {
                save_auto_product_formula(
                    $conn,
                    (int)$product['id'],
                    $product['name'],
                    $product['category']
                );

                save_auto_product_supplies(
                    $conn,
                    (int)$product['id'],
                    $product['name'],
                    $product['category']
                );
            } catch (Exception $e) {
                $_SESSION['formula_error'] = $e->getMessage();
            }
        }
    }

    header('Location: product_management.php?repaired=1');
    exit();
}

ensure_all_product_formulas($conn);
ensure_all_product_supplies($conn);

$products_list = mysqli_query(
    $conn,
    "SELECT *
     FROM products
     ORDER BY name ASC"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Product Management - BLACKHABIT</title>

<link
    rel="icon"
    type="image/x-icon"
    href="favicon_io/favicon.ico"
>

<link
    rel="stylesheet"
    href="style.css?v=<?php echo time(); ?>"
>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
/>

<style>

.form-container {
    background:#141414;
    border:1px solid #2e2e2e;
    padding:25px;
    border-radius:8px;
    margin-bottom:25px;
    color:#fff;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(
        auto-fit,
        minmax(200px,1fr)
    );
    gap:15px;
    margin-bottom:15px;
}

.form-group {
    display:flex;
    flex-direction:column;
    gap:6px;
}

.form-group label {
    font-size:13px;
    color:#c59d5f;
    font-weight:600;
}

.form-group input,
.form-group select {
    width:100%;
    box-sizing:border-box;
    padding:11px 12px;
    background:#1e1e1e;
    border:1px solid #2e2e2e;
    border-radius:6px;
    color:#fff;
    font-family:Poppins,sans-serif;
    font-size:14px;
}

.form-group input:focus,
.form-group select:focus {
    outline:none;
    border-color:#c59d5f;
}

.price-note {
    color:#888;
    font-size:11px;
    margin-top:2px;
}

.btn-submit {
    background:#c59d5f;
    color:#000;
    border:none;
    padding:12px 25px;
    font-weight:700;
    cursor:pointer;
    border-radius:5px;
    font-family:Poppins,sans-serif;
}

.btn-submit:hover {
    opacity:.9;
}

.alert {
    padding:12px 15px;
    border-radius:5px;
    margin-bottom:20px;
    font-size:14px;
}

.alert-success {
    background:rgba(40,167,69,.15);
    border:1px solid #28a745;
    color:#28a745;
}

.alert-danger {
    background:rgba(220,53,69,.15);
    border:1px solid #dc3545;
    color:#dc3545;
}

.thumbnail {
    width:55px;
    height:55px;
    object-fit:cover;
    border-radius:5px;
}

.action-btns a {
    margin-left:10px;
    font-size:17px;
    text-decoration:none;
}

.price-cell {
    white-space:nowrap;
    font-weight:700;
    font-size:14px;
}

.regular-price {
    color:#fff;
}

.large-price {
    color:#c59d5f;
}

.table-container {
    overflow-x:auto;
}

.product-table {
    width:100%;
    border-collapse:collapse;
}

.product-table th,
.product-table td {
    padding:12px;
}

.product-table th {
    color:#c59d5f;
}

.formula-container {
    margin-top:20px;
    padding:18px;
    border:1px solid #2e2e2e;
    border-radius:8px;
    background:#101010;
}

.formula-heading {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:15px;
}

.formula-heading h4 {
    margin:0;
    color:#c59d5f;
    font-size:16px;
}

.formula-heading p {
    margin:4px 0 0;
    color:#888;
    font-size:12px;
}

.formula-add,
.formula-remove {
    border:0;
    border-radius:6px;
    cursor:pointer;
    font-family:Poppins,sans-serif;
    font-weight:600;
}

.formula-add {
    background:#c59d5f;
    color:#000;
    padding:9px 12px;
}

.formula-remove {
    background:#dc3545;
    color:#fff;
    width:40px;
    height:40px;
}

.formula-row {
    display:grid;
    grid-template-columns:2fr 1fr 1fr 40px;
    gap:10px;
    margin-bottom:10px;
    align-items:center;
}

.formula-row select,
.formula-row input {
    width:100%;
    box-sizing:border-box;
    padding:10px;
    background:#1e1e1e;
    color:#fff;
    border:1px solid #2e2e2e;
    border-radius:6px;
    font-family:Poppins,sans-serif;
}

.formula-note {
    color:#777;
    font-size:11px;
    display:block;
    margin-top:5px;
}

.product-list-toolbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:0 0 18px;
}

.product-list-toolbar h2 {
    margin:0;
    color:#fff;
    font-size:20px;
}

.product-list-toolbar p {
    margin:4px 0 0;
    color:#888;
    font-size:12px;
}

.btn-new-product {
    border:0;
    border-radius:7px;
    background:#c59d5f;
    color:#000;
    padding:11px 17px;
    font-family:Poppins,sans-serif;
    font-weight:600;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}

.btn-new-product:hover {
    transform:translateY(-1px);
    filter:brightness(1.08);
}

.add-product-modal {
    position:fixed;
    inset:0;
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.add-product-modal.show {
    display:flex;
}

.add-product-overlay {
    position:absolute;
    inset:0;
    background:rgba(0,0,0,.75);
    backdrop-filter:blur(3px);
}

.add-product-dialog {
    position:relative;
    z-index:1;
    width:min(1050px,100%);
    max-height:calc(100vh - 40px);
    overflow:hidden;
    background:#111;
    border:1px solid #806332;
    border-radius:12px;
    box-shadow:0 25px 80px rgba(0,0,0,.6);
    display:flex;
    flex-direction:column;
}

.add-product-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:16px 20px;
    border-bottom:1px solid #2b2b2b;
    background:#151515;
}

.add-product-header h3 {
    margin:0;
    color:#c59d5f;
    font-size:18px;
}

.add-product-header p {
    margin:3px 0 0;
    color:#888;
    font-size:11px;
}

.add-product-close {
    width:36px;
    height:36px;
    border:0;
    border-radius:7px;
    background:#292929;
    color:#fff;
    cursor:pointer;
    font-size:17px;
}

.add-product-close:hover {
    background:#3a3a3a;
}

.add-product-body {
    overflow-y:auto;
    padding:18px 20px 22px;
}

.add-product-body form {
    margin:0;
}

body.product-modal-open {
    overflow:hidden;
}

.alert.alert-success {
    width:fit-content;
    max-width:min(520px,calc(100% - 30px));
    margin:10px 0 16px;
    padding:10px 15px;
    border-radius:7px;
    font-size:13px;
    line-height:1.4;
    display:inline-flex;
    align-items:center;
    gap:7px;
    transition:opacity .25s ease,transform .25s ease;
}

.alert.alert-success.is-hidden {
    opacity:0;
    transform:translateY(-6px);
    pointer-events:none;
}

@media(max-width:700px) {

    .form-grid {
        grid-template-columns:1fr;
    }

    .formula-heading {
        align-items:flex-start;
        flex-direction:column;
    }

    .formula-row {
        grid-template-columns:1fr 1fr 1fr 40px;
    }

    .product-list-toolbar {
        align-items:stretch;
        flex-direction:column;
    }

    .btn-new-product {
        width:100%;
        justify-content:center;
    }

    .add-product-modal {
        padding:8px;
    }

    .add-product-dialog {
        max-height:calc(100vh - 16px);
    }

    .add-product-header,
    .add-product-body {
        padding-left:14px;
        padding-right:14px;
    }
}

@media(max-width:500px) {

    .formula-row {
        grid-template-columns:1fr 1fr 40px;
    }

    .formula-row select {
        grid-column:1/-1;
    }
}

</style>

<style>

/* ==========================================================
   BLACKHABIT ADMIN - FINAL RESPONSIVE OVERRIDE
   Desktop / iPad landscape / iPad portrait / phone
   This section only changes layout; PHP/database logic is untouched.
   ========================================================== */

html, body {
    max-width: 100%;
    overflow-x: hidden;
}

.main {
    box-sizing: border-box;
    min-width: 0 !important;
    width: auto;
    max-width: 100%;
}

.main * {
    box-sizing: border-box;
}

.topbar {
    min-width: 0;
    max-width: 100%;
}

.topbar h1 {
    min-width: 0;
    overflow-wrap: anywhere;
}

.panel,
.sales-analytics,
.page-intro,
.form-container,
.section,
.dashboard-head {
    max-width: 100%;
    min-width: 0;
}

.table-container,
.formula-table-wrap,
.queue-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.product-table,
.order-management-table,
.history-table,
.queue-table {
    max-width: none;
}

img, canvas, video, iframe {
    max-width: 100%;
}

button, input, select, textarea {
    max-width: 100%;
}

@media (min-width: 769px) and (max-width: 1366px) {
    .main {
        padding-left: 18px !important;
        padding-right: 18px !important;
    }

    .topbar {
        gap: 12px;
    }

    .topbar h1 {
        font-size: clamp(20px, 2.2vw, 27px) !important;
    }

    .profile {
        min-width: 0;
    }

    .cards {
        gap: 12px !important;
    }

    .panel {
        padding: 16px !important;
    }

    .table-container {
        border-radius: 8px;
    }

    .product-table th,
    .product-table td,
    .order-management-table th,
    .order-management-table td,
    .history-table th,
    .history-table td {
        padding: 9px 8px;
        font-size: 12px;
    }
}

@media (min-width: 769px) and (max-width: 1100px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .sales-overview-header,
    .analytics-header-right,
    .panel-header {
        min-width: 0;
    }
}

@media (min-width: 769px) and (max-width: 900px) {
    .main {
        padding: 14px !important;
    }

    .cards {
        gap: 10px !important;
    }

    .card {
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .history-tools,
    .inventory-toolbar,
    .product-list-toolbar,
    .formula-heading {
        gap: 10px;
    }
}

@media (max-width: 768px) {
    .main {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin-left: 0 !important;
        padding: 12px !important;
    }

    .topbar {
        width: 100%;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 14px !important;
        padding-bottom: 12px !important;
    }

    .topbar h1 {
        flex: 1 1 160px;
        font-size: 20px !important;
        line-height: 1.25;
    }

    .profile {
        flex: 0 1 auto;
        margin-left: auto;
    }

    .profile > div {
        display: none;
    }

    .dashboard-head {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px;
    }

    .brand-quote {
        display: none !important;
    }

    .cards {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }

    .card {
        width: 100%;
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .inventory-toolbar,
    .history-tools,
    .product-list-toolbar,
    .formula-heading {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .history-search,
    .inventory-search-box,
    .supply-search-box {
        width: 100% !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter,
    .filter-form {
        width: 100%;
        gap: 8px !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter {
        flex-wrap: wrap;
    }

    .inventory-search-box,
    .supply-search-box {
        flex: 1 1 100%;
    }

    .inventory-search-btn,
    .inventory-reset-btn,
    .supply-search-btn,
    .supply-reset-btn,
    .add-ingredient-btn,
    .add-supply-trigger,
    .btn-new-product {
        min-height: 40px;
    }

    .table-container {
        overflow-x: auto !important;
        overflow-y: hidden;
    }

    .product-table,
    .order-management-table,
    .history-table,
    .queue-table {
        width: max-content !important;
        min-width: 700px;
    }

    .product-table th,
    .product-table td,
    .history-table th,
    .history-table td {
        white-space: nowrap;
        font-size: 11px;
        padding: 9px 7px;
    }

    .order-management-table {
        min-width: 950px !important;
    }

    .order-management-table th,
    .order-management-table td {
        font-size: 11px;
        padding: 9px 7px;
        white-space: nowrap;
    }

    .date-filter {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .date-filter > div,
    .date-actions {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .date-filter input,
    .date-actions .btn {
        width: 100% !important;
    }

    .order-filters {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
    }

    .filter-btn {
        width: 100%;
        min-width: 0 !important;
        white-space: normal !important;
        text-align: center;
        padding: 9px 6px !important;
    }

    .form-grid {
        grid-template-columns: 1fr !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 1fr 40px !important;
    }

    .add-product-dialog,
    .supply-modal-dialog {
        width: min(96vw, 700px) !important;
        max-width: 96vw !important;
        max-height: calc(100vh - 20px);
        overflow-y: auto;
    }

    .supply-modal-form-grid {
        grid-template-columns: 1fr !important;
    }

    .sales-analytics {
        padding: 14px !important;
    }

    .sales-overview-header,
    .analytics-header-right {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }

    .sales-range-form,
    .sales-range-form select,
    .graph-toggle-btn,
    .custom-apply-btn {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .custom-date-fields {
        flex-wrap: wrap !important;
    }

    .custom-date-fields input {
        width: 100% !important;
        flex: 1 1 130px;
    }

    .chart-container {
        height: 280px !important;
        padding: 12px !important;
    }

    .graph-canvas-wrap {
        height: 270px !important;
    }

    .sales-graph-container {
        padding: 12px !important;
    }

    .page-intro {
        padding: 14px !important;
    }

    .page-intro h2 {
        font-size: 17px !important;
    }
}

@media (max-width: 480px) {
    .main {
        padding: 9px !important;
    }

    .topbar h1 {
        font-size: 18px !important;
    }

    .quick-grid {
        grid-template-columns: 1fr !important;
    }

    .order-filters {
        grid-template-columns: 1fr !important;
    }

    .panel {
        padding: 10px !important;
    }

    .panel-header h2 {
        font-size: 16px !important;
    }

    .product-table,
    .history-table {
        min-width: 650px !important;
    }

    .order-management-table {
        min-width: 900px !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 40px !important;
    }

    .formula-row select {
        grid-column: 1 / -1;
    }
}


    /* Product search */
    .product-search-panel {
        display:flex;
        align-items:center;
        gap:10px;
        margin:0 0 18px;
        padding:14px;
        background:#141414;
        border:1px solid #2e2e2e;
        border-radius:10px;
    }

    .product-search-box {
        position:relative;
        flex:1 1 auto;
        min-width:0;
    }

    .product-search-box i {
        position:absolute;
        left:15px;
        top:50%;
        transform:translateY(-50%);
        color:#9b9b9b;
        pointer-events:none;
    }

    #productSearchInput {
        width:100%;
        height:44px;
        padding:0 15px 0 42px;
        border:1px solid #343434;
        border-radius:8px;
        background:#1b1b1b;
        color:#fff;
        font-family:inherit;
        font-size:14px;
        outline:none;
        box-sizing:border-box;
    }

    #productSearchInput:focus {
        border-color:#cda45b;
        box-shadow:0 0 0 2px rgba(205,164,91,.12);
    }

    #productSearchInput::placeholder {
        color:#777;
    }

    .product-search-reset {
        height:44px;
        min-width:90px;
        border:0;
        border-radius:8px;
        background:#333;
        color:#fff;
        font-family:inherit;
        font-weight:600;
        cursor:pointer;
        padding:0 16px;
    }

    .product-search-reset:hover {
        background:#444;
    }

    .product-search-count {
        flex:0 0 auto;
        color:#999;
        font-size:13px;
        white-space:nowrap;
    }

    .product-no-results td {
        padding:35px 15px !important;
        text-align:center;
        color:#999;
    }

    @media (max-width: 700px) {
        .product-search-panel {
            flex-wrap:wrap;
        }

        .product-search-box {
            flex:1 1 100%;
        }

        .product-search-reset {
            flex:0 0 auto;
        }

        .product-search-count {
            margin-left:auto;
        }
    }

</style>

</head>

<body>

<?php
// Use the existing shared BLACKHABIT admin sidebar.
// Keep the sidebar design/menu centralized in admin_sidebar.php.
include "admin_sidebar.php";
?>

<div class="main">

    <h1>Product Management</h1>

    <?php if (isset($_GET['updated'])): ?>

        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            Product and formula updated successfully!
        </div>

    <?php endif; ?>

    <?php if (!empty($success_msg)): ?>

        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_msg); ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>

        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>

    <?php endif; ?>


    <?php

    if (isset($_GET['edit_id'])):

        $edit_id =
            intval($_GET['edit_id']);

        $edit_query = mysqli_query(
            $conn,
            "SELECT *
             FROM products
             WHERE id = $edit_id
             LIMIT 1"
        );

        $edit_data =
            $edit_query
            ? mysqli_fetch_assoc($edit_query)
            : null;

        if ($edit_data):

            $edit_category =
                $edit_data['category'] ?? '';

            $edit_regular =
                $edit_data['regular_price']
                ?? $edit_data['price']
                ?? 0;

            $edit_large =
                $edit_data['large_price']
                ?? 0;

            $edit_has_sizes =
                in_array(
                    $edit_category,
                    $size_categories,
                    true
                );

            $edit_formula = mysqli_query(
                $conn,
                "SELECT
                    r.ingredient_id,
                    r.regular_qty,
                    r.large_qty,
                    i.ingredient_name
                 FROM recipes r
                 INNER JOIN ingredients i
                    ON i.id = r.ingredient_id
                 WHERE r.product_id=" .
                 (int)$edit_data['id'] .
                 "
                 ORDER BY i.ingredient_name ASC"
            );

            $edit_supplies = mysqli_query(
                $conn,
                "SELECT
                    ps.supply_id,
                    ps.regular_qty,
                    ps.large_qty,
                    s.supply_name
                 FROM product_supplies ps
                 INNER JOIN supplies s
                    ON s.id = ps.supply_id
                 WHERE ps.product_id=" .
                 (int)$edit_data['id'] .
                 "
                 ORDER BY s.supply_name ASC"
            );

    ?>

    <div
        class="form-container"
        style="border-color:#c59d5f;"
    >

        <h3 style="color:#c59d5f;margin-top:0;">

            <i class="fa-solid fa-pen-to-square"></i>

            Edit Product

        </h3>

        <form method="POST">

            <input
                type="hidden"
                name="id"
                value="<?php
                    echo (int)$edit_data['id'];
                ?>"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>Product Name</label>

                    <input
                        type="text"
                        name="name"
                        value="<?php
                            echo htmlspecialchars(
                                $edit_data['name']
                            );
                        ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Category</label>

                    <select
                        name="category"
                        id="editCategory"
                        required
                    >

                        <option value="">
                            Select Category
                        </option>

                        <?php foreach (
                            $categories as $cat
                        ): ?>

                            <option
                                value="<?php
                                    echo htmlspecialchars(
                                        str_replace(' ', '', strtolower($edit_category)) ===
                                        str_replace(' ', '', strtolower($cat))
                                            ? $edit_category
                                            : $cat
                                    );
                                ?>"
                                <?php
                                echo (
                                    str_replace(' ', '', strtolower($edit_category)) ===
                                    str_replace(' ', '', strtolower($cat))
                                )
                                    ? 'selected'
                                    : '';
                                ?>
                            >

                                <?php
                                echo htmlspecialchars($cat);
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label id="editRegularLabel">

                        <?php
                        echo $edit_has_sizes
                            ? 'Regular Price'
                            : 'Price';
                        ?>

                    </label>

                    <input
                        type="number"
                        name="regular_price"
                        id="editRegularPrice"
                        step="0.01"
                        min="0"
                        value="<?php
                            echo htmlspecialchars(
                                $edit_regular
                            );
                        ?>"
                        required
                    >

                </div>


                <div
                    class="form-group"
                    id="editLargePriceGroup"
                    style="<?php
                        echo $edit_has_sizes
                            ? 'display:flex;'
                            : 'display:none;';
                    ?>"
                >

                    <label>Large Price</label>

                    <input
                        type="number"
                        name="large_price"
                        id="editLargePrice"
                        step="0.01"
                        min="0"
                        value="<?php
                            echo htmlspecialchars(
                                $edit_large
                            );
                        ?>"
                        <?php
                        echo $edit_has_sizes
                            ? 'required'
                            : '';
                        ?>
                    >

                </div>


                <div class="form-group">

                    <label>Stock</label>

                    <input
                        type="number"
                        name="stock"
                        min="0"
                        step="1"
                        value="<?php
                            echo (int)(
                                $edit_data['stock'] ?? 0
                            );
                        ?>"
                        required
                    >

                </div>

            </div>


            <div class="formula-container">

                <div class="formula-heading">

                    <div>

                        <h4>
                            <i class="fa-solid fa-flask"></i>
                            Product Formula
                        </h4>

                        <p>
                            This product formula is generated automatically.
                        </p>

                    </div>

                    <button
                        type="button"
                        class="formula-add"
                        onclick="
                            addFormulaRow('editFormulaRows')
                        "
                    >

                        <i class="fa-solid fa-plus"></i>
                        Add Ingredient

                    </button>

                </div>


                <div
                    id="editFormulaRows"
                    class="formula-rows"
                >

                    <?php if (
                        $edit_formula &&
                        mysqli_num_rows($edit_formula) > 0
                    ): ?>

                        <?php while (
                            $formula =
                            mysqli_fetch_assoc($edit_formula)
                        ): ?>

                            <div class="formula-row">

                                <select
                                    name="recipe_ingredient_id[]"
                                    required
                                >

                                    <option value="">
                                        Select Ingredient
                                    </option>

                                    <?php foreach (
                                        $available_ingredients
                                        as $ing
                                    ): ?>

                                        <option
                                            value="<?php
                                                echo (int)$ing['id'];
                                            ?>"
                                            <?php
                                            echo (
                                                (int)$formula['ingredient_id']
                                                ===
                                                (int)$ing['id']
                                            )
                                                ? 'selected'
                                                : '';
                                            ?>
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $ing['ingredient_name']
                                            );
                                            ?>

                                            (
                                            <?php
                                            echo htmlspecialchars(
                                                $ing['unit']
                                            );
                                            ?>
                                            )

                                        </option>

                                    <?php endforeach; ?>

                                </select>


                                <input
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    name="recipe_regular_qty[]"
                                    value="<?php
                                        echo htmlspecialchars(rtrim(rtrim(number_format((float)$formula['regular_qty'], 3, '.', ''), '0'), '.'));
                                    ?>"
                                    placeholder="Regular Qty"
                                    required
                                >


                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    name="recipe_large_qty[]"
                                    value="<?php
                                        echo htmlspecialchars(rtrim(rtrim(number_format((float)$formula['large_qty'], 3, '.', ''), '0'), '.'));
                                    ?>"
                                    placeholder="Large Qty"
                                >


                                <button
                                    type="button"
                                    class="formula-remove"
                                    onclick="
                                        this.closest('.formula-row').remove()
                                    "
                                >

                                    <i class="fa-solid fa-trash"></i>

                                </button>

                            </div>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <div class="formula-row">

                            <select
                                name="recipe_ingredient_id[]"
                                required
                            >

                                <option value="">
                                    Select Ingredient
                                </option>

                                <?php foreach (
                                    $available_ingredients
                                    as $ing
                                ): ?>

                                    <option
                                        value="<?php
                                            echo (int)$ing['id'];
                                        ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $ing['ingredient_name']
                                        );
                                        ?>

                                        (
                                        <?php
                                        echo htmlspecialchars(
                                            $ing['unit']
                                        );
                                        ?>
                                        )

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <input
                                type="number"
                                step="0.001"
                                min="0.001"
                                name="recipe_regular_qty[]"
                                placeholder="Regular Qty"
                                required
                            >


                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                name="recipe_large_qty[]"
                                placeholder="Large Qty"
                            >


                            <button
                                type="button"
                                class="formula-remove"
                                onclick="
                                    this.closest('.formula-row').remove()
                                "
                            >

                                <i class="fa-solid fa-trash"></i>

                            </button>

                        </div>

                    <?php endif; ?>

                </div>


                <small class="formula-note">

                    For products without Large size,
                    leave Large Qty blank.

                </small>

            </div>

            <div class="formula-container">

                <div class="formula-heading">

                    <div>
                        <h4>
                            <i class="fa-solid fa-box"></i>
                            Product Supplies
                        </h4>

                        <p>
                            Supplies used by this product. These are updated automatically when the product is edited, and you can adjust them here.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="formula-add"
                        onclick="addSupplyRow('editSupplyRows')"
                    >
                        <i class="fa-solid fa-plus"></i>
                        Add Supply
                    </button>

                </div>

                <div
                    id="editSupplyRows"
                    class="formula-rows"
                >

                    <?php if (
                        $edit_supplies &&
                        mysqli_num_rows($edit_supplies) > 0
                    ): ?>

                        <?php while (
                            $supply = mysqli_fetch_assoc($edit_supplies)
                        ): ?>

                            <div class="formula-row">

                                <select
                                    name="product_supply_id[]"
                                    required
                                >
                                    <option value="">
                                        Select Supply
                                    </option>

                                    <?php foreach (
                                        $available_supplies
                                        as $available_supply
                                    ): ?>

                                        <option
                                            value="<?php
                                                echo (int)$available_supply['id'];
                                            ?>"
                                            <?php
                                            echo (
                                                (int)$supply['supply_id']
                                                ===
                                                (int)$available_supply['id']
                                            )
                                                ? 'selected'
                                                : '';
                                            ?>
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $available_supply['supply_name']
                                            );
                                            ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    name="product_supply_regular_qty[]"
                                    value="<?php
                                        echo htmlspecialchars(
                                            rtrim(
                                                rtrim(
                                                    number_format(
                                                        (float)$supply['regular_qty'],
                                                        3,
                                                        '.',
                                                        ''
                                                    ),
                                                    '0'
                                                ),
                                                '.'
                                            )
                                        );
                                    ?>"
                                    placeholder="Regular Qty"
                                    required
                                >

                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    name="product_supply_large_qty[]"
                                    value="<?php
                                        echo htmlspecialchars(
                                            rtrim(
                                                rtrim(
                                                    number_format(
                                                        (float)$supply['large_qty'],
                                                        3,
                                                        '.',
                                                        ''
                                                    ),
                                                    '0'
                                                ),
                                                '.'
                                            )
                                        );
                                    ?>"
                                    placeholder="Large Qty"
                                >

                                <button
                                    type="button"
                                    class="formula-remove"
                                    onclick="this.closest('.formula-row').remove()"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>

                            </div>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <div class="formula-row">

                            <select
                                name="product_supply_id[]"
                            >
                                <option value="">
                                    Select Supply
                                </option>

                                <?php foreach (
                                    $available_supplies
                                    as $available_supply
                                ): ?>

                                    <option
                                        value="<?php
                                            echo (int)$available_supply['id'];
                                        ?>"
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $available_supply['supply_name']
                                        );
                                        ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                name="product_supply_regular_qty[]"
                                placeholder="Regular Qty"
                            >

                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                name="product_supply_large_qty[]"
                                placeholder="Large Qty"
                            >

                            <button
                                type="button"
                                class="formula-remove"
                                onclick="this.closest('.formula-row').remove()"
                            >
                                <i class="fa-solid fa-trash"></i>
                            </button>

                        </div>

                    <?php endif; ?>

                </div>

                <small class="formula-note">
                    For products without Large size, leave Large Qty as 0.
                </small>

            </div>


            <button
                type="submit"
                name="update_product"
                class="btn-submit"
            >

                <i class="fa-solid fa-save"></i>

                Update Product

            </button>


            <a
                href="product_management.php"
                style="
                    margin-left:15px;
                    color:#fff;
                    text-decoration:none;
                "
            >

                Cancel

            </a>

        </form>

    </div>

    <?php

        endif;

    endif;

    ?>


    <div class="product-list-toolbar">

        <div>

            <h2>Product List</h2>

            <p>
                Manage your products and formulations.
            </p>

        </div>


        <button
            type="button"
            class="btn-new-product"
            id="openAddProductModal"
        >

            <i class="fa-solid fa-plus"></i>

            New Product

        </button>

    </div>


    <div
        class="add-product-modal"
        id="addProductModal"
        aria-hidden="true"
    >

        <div
            class="add-product-overlay"
            data-close-product-modal
        ></div>


        <div
            class="add-product-dialog"
            role="dialog"
            aria-modal="true"
        >

            <div class="add-product-header">

                <div>

                    <h3>
                        <i class="fa-solid fa-plus-circle"></i>
                        Add New Product
                    </h3>

                    <p>
                        Create the product. Its formula will be generated automatically.
                    </p>

                </div>


                <button
                    type="button"
                    class="add-product-close"
                    id="closeAddProductModal"
                    aria-label="Close"
                >

                    <i class="fa-solid fa-xmark"></i>

                </button>

            </div>


            <div class="add-product-body">

                <form
                    method="POST"
                    enctype="multipart/form-data"
                >

                    <div class="form-grid">

                        <div class="form-group">

                            <label>Product Name</label>

                            <input
                                type="text"
                                name="name"
                                placeholder="Product Name"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>Category</label>

                            <select
                                name="category"
                                id="addCategory"
                                required
                            >

                                <option value="">
                                    Select Category
                                </option>

                                <?php foreach (
                                    $categories as $cat
                                ): ?>

                                    <option
                                        value="<?php
                                            echo htmlspecialchars($cat);
                                        ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars($cat);
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="form-group">

                            <label id="addRegularLabel">
                                Price
                            </label>

                            <input
                                type="number"
                                name="regular_price"
                                id="addRegularPrice"
                                step="0.01"
                                min="0"
                                placeholder="Price"
                                required
                            >

                            <small
                                class="price-note"
                                id="priceNote"
                            >
                                Single price for this category.
                            </small>

                        </div>


                        <div
                            class="form-group"
                            id="addLargePriceGroup"
                            style="display:none;"
                        >

                            <label>
                                Large Price
                            </label>

                            <input
                                type="number"
                                name="large_price"
                                id="addLargePrice"
                                step="0.01"
                                min="0"
                                placeholder="Large Price"
                            >

                            <small class="price-note">
                                Price for the Large size.
                            </small>

                        </div>


                        <div class="form-group">

                            <label>Stock</label>

                            <input
                                type="number"
                                name="stock"
                                min="0"
                                step="1"
                                value="0"
                                placeholder="Stock"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Product Image
                            </label>

                            <input
                                type="file"
                                name="product_image"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >

                            <small
                                style="
                                    color:#888;
                                    font-size:12px;
                                "
                            >
                                Automatically saved to
                                blackhabit_api/images/
                            </small>

                        </div>

                    </div>


                    <div class="formula-container">

                        <div class="formula-heading">

                            <div>

                                <h4>
                                    <i class="fa-solid fa-flask"></i>
                                    Product Formula
                                </h4>

                                <p>
                                    Ingredients and quantities are generated automatically from the product formula template.
                                </p>

                            </div>


                            <span style="color:#888;font-size:12px;">Automatic</span>

                        </div>


                        <div
                            id="addFormulaRows"
                            class="formula-rows"
                        >

                            <div class="formula-auto-message" style="padding:14px;border:1px dashed #3a3a3a;border-radius:7px;color:#999;font-size:12px;">Formula will be created automatically when you save the product.</div></div>


                        <small class="formula-note">
                            The system automatically selects the ingredients and quantities for this product.
                        </small>

                    </div>


                    <button
                        type="submit"
                        name="add_product"
                        class="btn-submit"
                    >

                        <i class="fa-solid fa-save"></i>

                        Save Product

                    </button>

                </form>

            </div>

        </div>

    </div>


    <div class="product-search-panel" role="search" aria-label="Search products">
        <div class="product-search-box">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input
                type="search"
                id="productSearchInput"
                placeholder="Search product name or category..."
                autocomplete="off"
                aria-label="Search product name or category"
            >
        </div>

        <button type="button" class="product-search-reset" id="productSearchReset">
            <i class="fa-solid fa-rotate-left"></i>
            Reset
        </button>

        <span class="product-search-count" id="productSearchCount"></span>
    </div>

    <div class="table-container">

        <table class="product-table">

            <thead>

                <tr>

                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>REGULAR PRICE</th>
                    <th>LARGE PRICE</th>
                    <th>Stock</th>
                    <th>Actions</th>

                </tr>

            </thead>


            <tbody>

            <?php

            if (
                $products_list &&
                mysqli_num_rows($products_list) > 0
            ):

                while (
                    $row =
                    mysqli_fetch_assoc($products_list)
                ):

            ?>

                <tr class="product-row"
                    data-product-name="<?php echo htmlspecialchars(strtolower((string)($row['name'] ?? '')), ENT_QUOTES); ?>"
                    data-product-category="<?php echo htmlspecialchars(strtolower((string)($row['category'] ?? '')), ENT_QUOTES); ?>">

                    <td>

                        <?php

                        if (!empty($row['image'])):

                            $image_filename =
                                basename(
                                    str_replace(
                                        '\\',
                                        '/',
                                        $row['image']
                                    )
                                );

                            $admin_image_url =
                                '/blackhabit_api/images/' .
                                rawurlencode(
                                    $image_filename
                                );

                        ?>

                            <img
                                src="<?php
                                    echo htmlspecialchars(
                                        $admin_image_url
                                    );
                                ?>"
                                class="thumbnail"
                                alt="Product"
                                onerror="
                                    this.style.display='none';
                                    this.nextElementSibling.style.display='inline-block';
                                "
                            >

                            <i
                                class="fa-solid fa-image image-fallback"
                                style="
                                    display:none;
                                    color:#777;
                                    font-size:30px;
                                "
                            ></i>

                        <?php else: ?>

                            <i
                                class="fa-solid fa-image"
                                style="
                                    color:#777;
                                    font-size:30px;
                                "
                            ></i>

                        <?php endif; ?>

                    </td>


                    <td>

                        <strong>

                            <?php
                            echo htmlspecialchars(
                                $row['name']
                            );
                            ?>

                        </strong>

                    </td>


                    <td>

                        <?php
                        echo htmlspecialchars(
                            $row['category']
                        );
                        ?>

                    </td>


                    <td class="price-cell regular-price">

                        ₱<?php

                        echo number_format(
                            (float)(
                                $row['regular_price']
                                ??
                                $row['price']
                                ??
                                0
                            ),
                            2
                        );

                        ?>

                    </td>


                    <td class="price-cell large-price">

                        <?php

                        $large_price =
                            (float)(
                                $row['large_price']
                                ?? 0
                            );

                        if ($large_price > 0) {

                            echo '₱' .
                                number_format(
                                    $large_price,
                                    2
                                );

                        } else {

                            echo '—';

                        }

                        ?>

                    </td>


                    <td>

                        <?php

                        echo number_format(
                            (int)(
                                $row['stock'] ?? 0
                            )
                        );

                        ?>

                    </td>


                    <td class="action-btns">

                        <a
                            href="?edit_id=<?php
                                echo (int)$row['id'];
                            ?>"
                            style="color:#c59d5f;"
                            title="Edit Product"
                        >

                            <i
                                class="fa-solid fa-pen-to-square"
                            ></i>

                        </a>


                        <a
                            href="?delete_id=<?php
                                echo (int)$row['id'];
                            ?>"
                            style="color:#dc3545;"
                            title="Delete Product"
                            onclick="
                                return confirm(
                                    'Delete this product?'
                                );
                            "
                        >

                            <i
                                class="fa-solid fa-trash"
                            ></i>

                        </a>

                    </td>

                </tr>

            <?php

                endwhile;

            else:

            ?>

                <tr>

                    <td
                        colspan="7"
                        style="
                            text-align:center;
                            padding:30px;
                            color:#999;
                        "
                    >

                        No products found.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<script>

(function(){

    const modal =
        document.getElementById(
            'addProductModal'
        );

    const openBtn =
        document.getElementById(
            'openAddProductModal'
        );

    const closeBtn =
        document.getElementById(
            'closeAddProductModal'
        );

    if (
        !modal ||
        !openBtn ||
        !closeBtn
    ) {
        return;
    }

    function openModal(){

        modal.classList.add('show');

        modal.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.classList.add(
            'product-modal-open'
        );
    }

    function closeModal(){

        modal.classList.remove('show');

        modal.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.classList.remove(
            'product-modal-open'
        );
    }

    openBtn.addEventListener(
        'click',
        openModal
    );

    closeBtn.addEventListener(
        'click',
        closeModal
    );

    modal
        .querySelectorAll(
            '[data-close-product-modal]'
        )
        .forEach(
            el =>
                el.addEventListener(
                    'click',
                    closeModal
                )
        );

    document.addEventListener(
        'keydown',
        e => {

            if (
                e.key === 'Escape' &&
                modal.classList.contains('show')
            ) {
                closeModal();
            }

        }
    );

})();


const sizeCategories = [
    'Milk Tea',
    'Fruit Tea',
    'Non Coffee',
    'Frappes'
];


const addCategory =
    document.getElementById(
        'addCategory'
    );

const addRegularLabel =
    document.getElementById(
        'addRegularLabel'
    );

const addRegularPrice =
    document.getElementById(
        'addRegularPrice'
    );

const addLargePriceGroup =
    document.getElementById(
        'addLargePriceGroup'
    );

const addLargePrice =
    document.getElementById(
        'addLargePrice'
    );

const priceNote =
    document.getElementById(
        'priceNote'
    );


function updateAddPriceFields(){

    if (!addCategory) {
        return;
    }

    const category =
        addCategory.value;

    if (
        sizeCategories.includes(
            category
        )
    ) {

        addRegularLabel.textContent =
            'Regular Price';

        addRegularPrice.placeholder =
            'Regular Price';

        addLargePriceGroup.style.display =
            'flex';

        addLargePrice.required =
            true;

        priceNote.textContent =
            'This category uses Regular + Large sizes.';

    } else {

        addRegularLabel.textContent =
            'Price';

        addRegularPrice.placeholder =
            'Price';

        addLargePriceGroup.style.display =
            'none';

        addLargePrice.required =
            false;

        addLargePrice.value =
            '0';

        priceNote.textContent =
            'Single price for this category.';
    }
}


if (addCategory) {

    addCategory.addEventListener(
        'change',
        updateAddPriceFields
    );

    updateAddPriceFields();
}


const editCategory =
    document.getElementById(
        'editCategory'
    );

const editRegularLabel =
    document.getElementById(
        'editRegularLabel'
    );

const editLargePriceGroup =
    document.getElementById(
        'editLargePriceGroup'
    );

const editLargePrice =
    document.getElementById(
        'editLargePrice'
    );


function updateEditPriceFields(){

    if (!editCategory) {
        return;
    }

    const category =
        editCategory.value;

    if (
        sizeCategories.includes(
            category
        )
    ) {

        editRegularLabel.textContent =
            'Regular Price';

        editLargePriceGroup.style.display =
            'flex';

        editLargePrice.required =
            true;

    } else {

        editRegularLabel.textContent =
            'Price';

        editLargePriceGroup.style.display =
            'none';

        editLargePrice.required =
            false;

        editLargePrice.value =
            '0';
    }
}


if (editCategory) {

    editCategory.addEventListener(
        'change',
        updateEditPriceFields
    );

    updateEditPriceFields();
}


function addFormulaRow(containerId){

    const container =
        document.getElementById(
            containerId
        );

    if (!container) {
        return;
    }

    const first =
        container.querySelector(
            '.formula-row'
        );

    if (!first) {
        return;
    }

    const row =
        first.cloneNode(true);

    row.querySelectorAll(
        'input'
    ).forEach(
        input => {
            input.value = '';
        }
    );

    const select =
        row.querySelector(
            'select'
        );

    if (select) {
        select.selectedIndex = 0;
    }

    container.appendChild(row);
}

function addSupplyRow(containerId){

    const container =
        document.getElementById(
            containerId
        );

    if (!container) {
        return;
    }

    const first =
        container.querySelector(
            '.formula-row'
        );

    if (!first) {
        return;
    }

    const row =
        first.cloneNode(true);

    row.querySelectorAll(
        'input'
    ).forEach(
        input => {
            input.value = '';
        }
    );

    const select =
        row.querySelector(
            'select'
        );

    if (select) {
        select.selectedIndex = 0;
    }

    container.appendChild(row);
}

</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('productSearchInput');
    const resetButton = document.getElementById('productSearchReset');
    const countLabel = document.getElementById('productSearchCount');
    const table = document.querySelector('.product-table');

    if (!searchInput || !table) {
        return;
    }

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody ? tbody.querySelectorAll('tr.product-row') : []);
    const emptyServerRow = tbody ? tbody.querySelector('tr:not(.product-row)') : null;

    let noResultsRow = document.getElementById('productNoSearchResults');

    if (!noResultsRow && tbody) {
        noResultsRow = document.createElement('tr');
        noResultsRow.id = 'productNoSearchResults';
        noResultsRow.className = 'product-no-results';
        noResultsRow.style.display = 'none';

        const cell = document.createElement('td');
        cell.colSpan = table.querySelectorAll('thead th').length || 7;
        cell.innerHTML = '<i class="fa-solid fa-box-open"></i> No products found.';
        noResultsRow.appendChild(cell);
        tbody.appendChild(noResultsRow);
    }

    function updateProductSearch() {
        const query = searchInput.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach(function (row) {
            const name = row.dataset.productName || '';
            const category = row.dataset.productCategory || '';
            const matches = query === '' || name.includes(query) || category.includes(query);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visible++;
            }
        });

        if (noResultsRow) {
            noResultsRow.style.display = (rows.length > 0 && visible === 0) ? '' : 'none';
        }

        if (countLabel) {
            countLabel.textContent = query === ''
                ? (rows.length + ' products')
                : (visible + ' result' + (visible === 1 ? '' : 's') + ' found');
        }

        if (emptyServerRow && rows.length > 0) {
            emptyServerRow.style.display = 'none';
        }
    }

    searchInput.addEventListener('input', updateProductSearch);

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            searchInput.value = '';
            updateProductSearch();
            searchInput.focus();
        });
    }

    updateProductSearch();
});
</script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        document
            .querySelectorAll(
                '.alert.alert-success'
            )
            .forEach(
                function (alert) {

                    setTimeout(
                        function () {

                            alert.classList.add(
                                'is-hidden'
                            );

                            setTimeout(
                                function () {
                                    alert.remove();
                                },
                                300
                            );

                        },
                        3000
                    );

                }
            );

    }
);

</script>

</body>
</html>
