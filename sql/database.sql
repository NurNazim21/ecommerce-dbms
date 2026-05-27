-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: May 26, 2026 at 12:18 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ecommerce_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`id`, `user_id`, `product_id`, `quantity`, `created_at`) VALUES
(56, 8, 10, 2, '2026-05-24 13:46:58'),
(57, 17, 12, 1, '2026-05-24 16:14:59'),
(58, 7, 9, 1, '2026-05-25 00:05:59');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `status`, `rejection_reason`) VALUES
(1, 'Electronics', 'Mobiles, Laptops, Gadgets and Accessories', 'approved', NULL),
(2, 'Clothing', NULL, 'approved', NULL),
(3, 'Books', 'Educational and Story Books', 'approved', NULL),
(4, 'Furniture', NULL, 'approved', NULL),
(8, 'Fruits', 'Fruits are mature, seed-bearing reproductive structures of plants, classified as fleshy or dry.', 'approved', NULL),
(9, 'Fashion', '', 'approved', NULL),
(10, 'Micro Components', 'Hi-fi component considerably smaller than a minicomponent and much smaller than a standard-size component', 'rejected', '');

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `discount_type` enum('percentage','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_order_amount` decimal(10,2) DEFAULT 0.00,
  `max_uses` int(11) DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `coupons`
--

INSERT INTO `coupons` (`id`, `code`, `discount_type`, `discount_value`, `min_order_amount`, `max_uses`, `used_count`, `expiry_date`, `is_active`, `created_at`) VALUES
(2, 'E100', 'fixed', 100.00, 500.00, NULL, 0, '2026-05-25', 1, '2026-05-25 16:53:01'),
(3, 'E20', 'percentage', 20.00, 1000.00, NULL, 0, '2026-05-27', 1, '2026-05-25 22:08:40');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `coupon_code` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tracking_number` varchar(50) DEFAULT NULL,
  `estimated_delivery` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `seller_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `total_amount`, `shipping_cost`, `discount_amount`, `coupon_code`, `status`, `created_at`, `tracking_number`, `estimated_delivery`, `notes`, `seller_id`) VALUES
(14, 6, 2100.00, 0.00, 0.00, NULL, 'Cancelled', '2026-05-06 05:48:20', NULL, NULL, NULL, NULL),
(15, 7, 10.00, 0.00, 0.00, NULL, 'Processing', '2026-05-06 10:28:40', NULL, NULL, NULL, NULL),
(16, 7, 500.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:15:48', NULL, NULL, NULL, NULL),
(17, 7, 500.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:18:18', NULL, NULL, NULL, NULL),
(18, 7, 500.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:22:54', NULL, NULL, NULL, NULL),
(19, 7, 700.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:25:27', NULL, NULL, NULL, NULL),
(20, 7, 500.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:29:49', NULL, NULL, NULL, NULL),
(21, 7, 3000.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:31:34', NULL, NULL, NULL, NULL),
(22, 7, 3000.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:33:52', NULL, NULL, NULL, NULL),
(23, 7, 24999.00, 0.00, 0.00, NULL, 'Pending', '2026-05-12 16:36:34', NULL, NULL, NULL, NULL),
(24, 7, 2700.00, 0.00, 0.00, 'DISCOUNT10', 'Pending', '2026-05-12 17:27:58', NULL, NULL, NULL, NULL),
(25, 7, 13500.00, 0.00, 0.00, 'DISCOUNT10', 'Pending', '2026-05-12 22:06:36', NULL, NULL, NULL, NULL),
(26, 7, 9000.00, 0.00, 0.00, 'DISCOUNT10', 'Pending', '2026-05-13 08:46:12', NULL, NULL, NULL, NULL),
(27, 7, 50.00, 0.00, 0.00, NULL, 'Pending', '2026-05-23 06:46:00', NULL, NULL, NULL, NULL),
(28, 7, 24999.00, 0.00, 0.00, NULL, 'Pending', '2026-05-23 07:22:44', NULL, NULL, NULL, NULL),
(29, 7, 5000.00, 0.00, 0.00, NULL, 'Pending', '2026-05-24 06:32:18', NULL, NULL, NULL, NULL),
(30, 7, 30060.00, 0.00, 0.00, NULL, 'Pending', '2026-05-25 00:34:04', NULL, NULL, '{\"name\":\"Nazim\",\"phone\":\"01918539046\",\"email\":\"simoneoy.77@gmail.com\",\"address\":\"North Mugdha para dhaka-1214\\r\\n128\\/A modinabaag kindergarted\",\"city\":\"Dhaka\",\"zip\":\"1214\",\"country\":\"Bangladesh\",\"method\":\"standard\",\"notes\":\"Carefully\"}', NULL),
(31, 7, 4960.00, 60.00, 100.00, 'E100', 'Pending', '2026-05-25 17:48:10', NULL, NULL, '{\"name\":\"Nazim\",\"phone\":\"01918539046\",\"email\":\"simoneoy.77@gmail.com\",\"address\":\"North Mugdha para dhaka-1214\\r\\n128\\/A modinabaag kindergarted\",\"city\":\"Dhaka\",\"zip\":\"1214\",\"country\":\"Bangladesh\",\"method\":\"standard\",\"notes\":\"hii\"}', NULL),
(32, 7, 26060.00, 60.00, 6500.00, 'E20', 'Pending', '2026-05-25 22:09:06', NULL, NULL, '{\"name\":\"Nazim\",\"phone\":\"01918539046\",\"email\":\"fanari.bd@gmail.com\",\"address\":\"Dhaka,Khilgaon\",\"city\":\"Dhaka\",\"zip\":\"1219\",\"country\":\"Bangladesh\",\"method\":\"standard\",\"notes\":\"carefull\"}', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `price`) VALUES
(23, 14, 3, 3, 700.00),
(24, 15, 8, 1, 10.00),
(25, 16, 4, 1, 500.00),
(26, 17, 2, 1, 500.00),
(27, 18, 4, 1, 500.00),
(28, 19, 3, 1, 700.00),
(29, 20, 4, 1, 500.00),
(30, 21, 7, 1, 3000.00),
(31, 22, 7, 1, 3000.00),
(32, 23, 6, 1, 24999.00),
(33, 24, 7, 1, 3000.00),
(34, 25, 5, 1, 15000.00),
(35, 26, 9, 2, 5000.00),
(36, 27, 8, 5, 10.00),
(37, 28, 6, 1, 24999.00),
(38, 29, 12, 1, 5000.00),
(39, 30, 10, 1, 30000.00),
(40, 31, 12, 1, 5000.00),
(41, 32, 10, 1, 30000.00),
(42, 32, 16, 1, 2500.00);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `payment_method`, `payment_status`) VALUES
(14, 14, 'Cash on Delivery', 'Pending'),
(15, 15, 'Cash on Delivery', 'Pending'),
(16, 16, 'Cash on Delivery', 'Pending'),
(17, 17, 'Cash on Delivery', 'Pending'),
(18, 18, 'Cash on Delivery', 'Pending'),
(19, 19, 'Cash on Delivery', 'Pending'),
(20, 20, 'Cash on Delivery', 'Pending'),
(21, 21, 'Cash on Delivery', 'Pending'),
(22, 22, 'Cash on Delivery', 'Pending'),
(23, 23, 'Cash on Delivery', 'Pending'),
(24, 24, 'Cash on Delivery', 'Pending'),
(25, 25, 'Cash on Delivery', 'Pending'),
(26, 26, 'Cash on Delivery', 'Pending'),
(27, 27, 'Cash on Delivery', 'Pending'),
(28, 28, 'Cash on Delivery', 'Pending'),
(29, 29, 'Cash on Delivery', 'Pending'),
(30, 30, 'cod', 'Pending'),
(31, 31, 'Cash on Delivery', 'Pending'),
(32, 32, 'Cash on Delivery', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT 'https://via.placeholder.com/600x400?text=No+Image',
  `description` text DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `price`, `stock`, `category_id`, `image`, `description`, `brand`, `seller_id`, `status`, `rejection_reason`, `created_at`) VALUES
(1, 'Laptop', 80000.00, 9, 1, 'assets/images/products/6a0f5b398e88b.jpg', '', '', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(2, 'T-Shirt', 500.00, 46, 2, 'assets/images/products/6a0f5bbf8b225.jpg', '', '', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(3, 'Database Book', 700.00, 25, 3, 'assets/images/products/6a0f5b134d401.jpg', '', '', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(4, 'Watch', 500.00, 2, 1, 'assets/images/products/6a0f5b02a68d9.jpg', '', '', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(5, 'Smart TV', 15000.00, 4, 1, 'assets/images/products/69f3b62abe1b2.jpg', '', '', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(6, 'Apple AirPods Pro 2', 24999.00, 16, 1, 'assets/images/products/6a0f5a1ace264.jpg', 'Active noise cancellation wireless earbuds', 'Apple', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(7, 'Stylee Ventral Arm Chair-Lime Green', 3000.00, 2, 4, 'assets/images/products/69f3c1279d044.png', 'This chair is preferred for the attractive look and comfort. Variation of color added an extra feather to the product. As it is made of 100% virgin polypropylene material, and/ combination of metal and foam ensure excellent look and comfort.', 'RFL', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(8, 'Banana', 10.00, 15, 8, 'assets/images/products/69f9f28c74077.jpeg', 'Banana is a healthy fruit.', 'Sagor', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(9, ' Men’s Slim Fit Suit ', 5000.00, 3, 2, 'assets/images/products/6a03abf5c19c7.jpg', 'Material: Cotton polyester blended, The Mens Suits Will Be Comfortable, Breathable, Softer, Smoother, Easier to Wash and Keep. The Shape Slim fit , simple style, basic suit suitable for everyday wear\r\nButton Closure: This mens suit with full shoulder design and slim cut with 3D draping. Slim fit suits for men are a little tighter than a regular fit suit. When you put on it makes you slimmer, sharper, look modern and handsome\r\nIt\'s suitable for multi-occasions, like wedding, daily life, business meeting, any fashion forward parties, any grandly holiday, ect. It is also prefect for young men to prepare for the Homingcming and back to school\r\nSuit is men\'s symbol. There is nothing as cool as confident. This prefect and comfortable tuxedo suit doesn\'t cost hundreds of dollars to buy. It\'s a very affordable price\r\nDry cleaning, Low iron if possible. Please reference to the“ product description” before purchasing, choose your favorite colors and style, and refer the size chart , choose according to your size', 'ILLIYEEN', NULL, 'approved', NULL, '2026-05-24 16:05:14'),
(10, 'Delux Bed', 30000.00, 1, 4, 'assets/images/products/1779484428_6a10c70c00d5a.jpg', 'Modern bedroom cupboard designs', 'RFL', 8, 'approved', NULL, '2026-05-24 16:05:14'),
(12, 'Lounge Chair', 5000.00, 3, 4, 'assets/images/products/1779579516_6a123a7c84965.jpg', 'Grant Featherston Contour Lounge Chair - The Grant Featherston Contour Lounge Chair by Origins by Inmod updates the classic armchair design to create a fresh, contemporary look that opens up your mood.', 'International', 8, 'approved', NULL, '2026-05-24 16:05:14'),
(15, 'jkjk', 45.00, 53, 9, 'assets/images/no-image.png', 'fdgdhef', 'ILLIYEEN', 8, 'rejected', 'fake product', '2026-05-24 16:05:14'),
(16, 'Converse', 2500.00, 4, 9, 'assets/images/products/1779746677_6a14c775d631e.jpg', 'Nike shoes are recognized globally for their blend of performance-driven engineering and iconic streetwear styling.', 'NIKE', 15, 'approved', NULL, '2026-05-25 22:04:37');

-- --------------------------------------------------------

--
-- Table structure for table `products_backup`
--

CREATE TABLE `products_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(150) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT 'https://via.placeholder.com/600x400?text=No+Image'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products_backup`
--

INSERT INTO `products_backup` (`id`, `name`, `price`, `stock`, `category_id`, `image`) VALUES
(1, 'Laptop', 80000.00, 9, 1, 'https://via.placeholder.com/600x400?text=No+Image'),
(2, 'T-Shirt', 500.00, 48, 2, 'https://via.placeholder.com/600x400?text=No+Image'),
(3, 'Database Book', 700.00, 30, 3, 'https://via.placeholder.com/600x400?text=No+Image'),
(4, 'Watch', 500.00, 4, 1, 'https://via.placeholder.com/600x400?text=No+Image'),
(5, 'Smart TV', 15000.00, 5, 1, 'assets/images/products/69f3b62abe1b2.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `product_moderation_logs`
--

CREATE TABLE `product_moderation_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `moderator_id` int(11) DEFAULT NULL,
  `action` enum('approved','rejected','edited') DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_moderation_logs`
--

INSERT INTO `product_moderation_logs` (`id`, `product_id`, `moderator_id`, `action`, `reason`, `created_at`) VALUES
(1, 10, 3, 'rejected', 'fake product', '2026-05-23 22:55:06'),
(2, 10, 3, '', 'fake product', '2026-05-23 23:08:50'),
(3, 10, 3, 'rejected', 'fake product', '2026-05-23 23:09:03'),
(4, 10, 3, 'rejected', 'fake product', '2026-05-23 23:09:13'),
(5, 10, 3, 'approved', '', '2026-05-23 23:09:37'),
(6, 10, 3, 'rejected', 'fake product', '2026-05-23 23:09:45'),
(7, 12, 3, 'rejected', 'fake product', '2026-05-23 23:39:00'),
(8, 12, 9, 'rejected', '', '2026-05-24 01:26:17'),
(9, 12, 9, 'rejected', 'fake product', '2026-05-24 01:26:35'),
(12, 12, 9, 'rejected', 'fake product', '2026-05-24 02:06:52'),
(13, 10, 9, 'rejected', '', '2026-05-24 02:07:38'),
(15, 15, 9, 'rejected', 'fake product', '2026-05-24 05:36:03'),
(16, 1, 9, 'approved', '', '2026-05-24 05:36:20'),
(17, 9, 9, 'approved', '', '2026-05-24 05:36:44'),
(18, 9, 9, 'approved', '', '2026-05-24 05:36:46'),
(19, 8, 9, 'approved', '', '2026-05-24 05:36:47'),
(20, 6, 9, 'approved', '', '2026-05-24 05:36:49'),
(21, 7, 9, 'approved', '', '2026-05-24 05:36:51'),
(22, 5, 9, 'approved', '', '2026-05-24 05:36:53'),
(23, 4, 9, 'approved', '', '2026-05-24 05:36:54'),
(24, 3, 9, 'approved', '', '2026-05-24 05:36:55'),
(25, 2, 9, 'approved', '', '2026-05-24 05:36:56'),
(26, 12, 9, 'approved', '', '2026-05-24 05:37:20'),
(27, 10, 9, 'approved', '', '2026-05-24 05:39:21'),
(28, NULL, 9, '', '', '2026-05-24 05:48:21'),
(29, NULL, 9, '', '', '2026-05-24 05:48:25'),
(30, NULL, 9, '', '', '2026-05-24 05:48:28'),
(31, NULL, 9, '', '', '2026-05-24 05:48:32'),
(32, NULL, 9, '', 'fake product', '2026-05-24 05:49:21'),
(33, NULL, 9, '', '', '2026-05-24 05:49:25'),
(34, NULL, 9, '', '', '2026-05-24 09:38:56'),
(35, NULL, 9, '', '', '2026-05-24 09:46:02'),
(36, NULL, 9, '', '', '2026-05-24 09:46:38'),
(37, 16, 16, 'approved', '', '2026-05-25 22:05:42');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `rating` int(11) DEFAULT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seller_profiles`
--

CREATE TABLE `seller_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `shop_name` varchar(150) NOT NULL,
  `shop_slug` varchar(150) DEFAULT NULL,
  `shop_logo` varchar(255) DEFAULT NULL,
  `shop_banner` varchar(255) DEFAULT NULL,
  `shop_description` text DEFAULT NULL,
  `seller_address` text DEFAULT NULL,
  `verification_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `commission_rate` decimal(5,2) DEFAULT 10.00,
  `total_sales` decimal(12,2) DEFAULT 0.00,
  `rating` decimal(3,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seller_transactions`
--

CREATE TABLE `seller_transactions` (
  `id` int(11) NOT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `gross_amount` decimal(10,2) DEFAULT NULL,
  `commission_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `payout_status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `courier_name` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `shipping_status` enum('processing','shipped','in_transit','delivered') DEFAULT 'processing',
  `estimated_delivery` date DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','user','seller','category_manager') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `phone` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `seller_status` enum('pending','approved','rejected') DEFAULT NULL,
  `shop_name` varchar(100) DEFAULT NULL,
  `seller_request_at` timestamp NULL DEFAULT NULL,
  `seller_approved_at` timestamp NULL DEFAULT NULL,
  `seller_address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`, `phone`, `address`, `city`, `seller_status`, `shop_name`, `seller_request_at`, `seller_approved_at`, `seller_address`) VALUES
(1, 'Admin User', 'admin@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '2026-04-30 14:10:12', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'Nur Hossain Nazim', 'Nazim21@gmail.com', '$2y$10$oN0BcnGN8b5syWwNEeTVpeJrHqVVK2WJwS9pg.m1Rf.eMcSB.wXOu', 'admin', '2026-04-30 21:45:41', '01954900122', 'North Mugdha para dhaka-1214\r\n128/A modinabaag kindergarted', 'Dhaka', NULL, NULL, NULL, NULL, NULL),
(6, 'Kanji Fatema Bipa', 'bipa@gmail.com', '$2y$10$CsF6/J6FrBqlc4cECqgkTummBBusdT7O./Bt6MNL8zojQvCdHvk02', 'admin', '2026-05-06 05:45:42', '01301450551', NULL, 'Dinajpur', NULL, NULL, NULL, NULL, NULL),
(7, 'Nazim', 'nur@gmail.com', '$2y$10$1IiUYh5EiRVmAQpLrRLKuOLqzHpB1vL/Y.E/zf3Dt1UCfUFGvgqqe', 'user', '2026-05-06 10:10:38', '01918539046', NULL, 'Dhaka', NULL, NULL, NULL, NULL, NULL),
(8, 'bipa12', 'bipa1223@gmail.com', '$2y$10$nl3791Ns.RIFS6RwBcZUveXgTuIsOOBrInf//VDF4gj9EHaZBjxPW', 'seller', '2026-05-20 08:36:57', '01918539046', NULL, 'Cumilla', 'approved', 'FANARI', '2026-05-20 08:36:57', '2026-05-23 23:00:34', 'dhaka123'),
(9, 'category', 'category@gmail.com', '$2y$10$g9lWgON0hGYOWrvocRKpbunOXRfoIY4Bu5FSVhbG65Jf.zIXG/r4u', 'category_manager', '2026-05-22 19:26:19', '01301450551', NULL, 'Dhaka', NULL, NULL, NULL, NULL, NULL),
(10, 'Moni', 'moni@gmail.com', '$2y$10$8FfZV2SRKLCN6hzCtlMKf.LWSLA0RKwsYAa.VXShVzESOcvqro3zW', 'user', '2026-05-24 00:15:13', '08060446048', NULL, '仙台市太白区', NULL, NULL, NULL, NULL, NULL),
(14, 'Nazmul Hasan', 'najzmul12@gmail.com', '$2y$10$SczN8AKQ5Ropbs4BWBh1HOq7EHguwRoj/0a2iFxbmEeEZcdOxS6Vu', 'user', '2026-05-24 11:27:06', '+818060446048', NULL, 'Sendai', NULL, NULL, NULL, NULL, NULL),
(15, 'Nazmul Hasan', 'najzmul1@gmail.com', '$2y$10$FX4jah2TuUfKwTrxIiyVIevlvPu7PnqpFO9cd6iSFu7TUMoOuh7pm', 'seller', '2026-05-24 11:29:37', '+818060446048', NULL, 'Sendai', 'approved', 'Komami', '2026-05-24 11:29:37', '2026-05-25 21:55:51', '1-4-8-306\r\nアイリスヒルズ'),
(16, 'Nazmul Hasan', 'najzmul12145@gmail.com', '$2y$10$nbnj2lB00HAS5wWtjSgVXebah3ohM9Sr7oU9hIHhyZstVjhQSSAv2', 'category_manager', '2026-05-24 11:31:15', '+818060446048', NULL, 'Sendai', 'approved', NULL, NULL, '2026-05-24 17:15:18', NULL),
(17, 'Nazmul Hasan', 'najzmul1214@gmail.com', '$2y$10$Upd2Aw8jgJkQ67sI00HH9uOtU.6Yl2hJa4/CZZi3IiF/v0vqSIrjO', 'user', '2026-05-24 16:14:24', '+818060446048', NULL, 'Sendai', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vendor_orders`
--

CREATE TABLE `vendor_orders` (
  `id` int(11) NOT NULL,
  `parent_order_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `shipping_cost` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','ready_to_ship','shipped','delivered','cancelled','returned') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wishlist`
--

CREATE TABLE `wishlist` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wishlist`
--

INSERT INTO `wishlist` (`id`, `user_id`, `product_id`, `created_at`) VALUES
(41, 7, 8, '2026-05-12 22:05:35'),
(44, 8, 10, '2026-05-24 13:46:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cart` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_name` (`permission_name`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `product_moderation_logs`
--
ALTER TABLE `product_moderation_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `moderator_id` (`moderator_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_review` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `seller_profiles`
--
ALTER TABLE `seller_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `shop_slug` (`shop_slug`);

--
-- Indexes for table `seller_transactions`
--
ALTER TABLE `seller_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `seller_id` (`seller_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_order_id` (`parent_order_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_wishlist` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `product_moderation_logs`
--
ALTER TABLE `product_moderation_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seller_profiles`
--
ALTER TABLE `seller_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seller_transactions`
--
ALTER TABLE `seller_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wishlist`
--
ALTER TABLE `wishlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `products_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_moderation_logs`
--
ALTER TABLE `product_moderation_logs`
  ADD CONSTRAINT `product_moderation_logs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_moderation_logs_ibfk_2` FOREIGN KEY (`moderator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `seller_profiles`
--
ALTER TABLE `seller_profiles`
  ADD CONSTRAINT `seller_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `seller_transactions`
--
ALTER TABLE `seller_transactions`
  ADD CONSTRAINT `seller_transactions_ibfk_1` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `seller_transactions_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  ADD CONSTRAINT `vendor_orders_ibfk_1` FOREIGN KEY (`parent_order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `vendor_orders_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD CONSTRAINT `wishlist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `wishlist_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
