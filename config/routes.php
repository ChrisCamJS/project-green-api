<?php
use App\Router;

$router = new Router();



// CORE RECIPE ENDPOINTS 
$router->add('GET', '/recipes', 'RecipeController', 'getAllRecipes');
$router->add('POST', '/recipes', 'RecipeController', 'createRecipe'); 
$router->add('GET', '/recipes/single', 'RecipeController', 'getRecipeById');

// AUTHENTICATION & USERS
$router->add('POST', '/login', 'AuthController', 'login');
$router->add('POST', '/register', 'AuthController', 'register');
$router->add('POST', '/logout', 'AuthController', 'logout');
$router->add('POST', '/users/deduct-token', 'AuthController', 'deductToken');
$router->add('GET', '/admin/invites', 'AdminController', 'getInviteCodes');
$router->add('POST', '/admin/invites', 'AdminController', 'generateInviteCode');
$router->add('PUT', '/admin/invites/toggle', 'AdminController', 'toggleInviteCode');
$router->add('POST', '/users/request-reset', 'AuthController', 'requestPasswordReset');
$router->add('POST', '/users/reset-password', 'AuthController', 'resetPassword');

// RECIPE MANAGEMENT
$router->add('PUT', '/recipes', 'RecipeController', 'updateRecipe');
$router->add('PUT', '/recipes/draft', 'RecipeController', 'toggleDraft');
$router->add('DELETE', '/recipes', 'RecipeController', 'deleteRecipe');
$router->add('POST', '/recipes/rate', 'RecipeController', 'rateRecipe');

// COMMENTS & ENGAGEMENT
$router->add('GET', '/recipes/comments', 'RecipeController', 'getRecipeComments');
$router->add('POST', '/recipes/comments', 'RecipeController', 'addRecipeComment');
$router->add('POST', '/recipes/comments/vote', 'RecipeController', 'voteOnComment');

// FAVORITES & USER RECIPES
$router->add('POST', '/recipes/favorite', 'RecipeController', 'toggleFavorite');
$router->add('GET', '/recipes/favorites', 'RecipeController', 'getUserFavorites');
$router->add('POST', '/recipes/attach-photo', 'RecipeController', 'publishWithPhoto');
$router->add('POST', '/recipes/upgrade-nutrition', 'RecipeController', 'upgradeRecipeNutrition');
$router->add('POST', '/recipes/publish-draft', 'RecipeController', 'publishDraft');

// USER PROFILES & FOLLOWING
$router->add('GET', '/users/profile', 'ProfileController', 'getProfile');
$router->add('PUT', '/users/profile', 'ProfileController', 'updateProfile');
$router->add('PUT', '/users/wellness', 'ProfileController', 'updateWellness');
$router->add('PUT', '/users/email', 'ProfileController', 'updateEmail');
$router->add('PUT', '/users/password', 'ProfileController', 'updatePassword');
$router->add('POST', '/users/follow', 'ProfileController', 'toggleFollow');
$router->add('GET', '/users/following', 'ProfileController', 'getFollowing');

// EXTRAS & MEDIA
$router->add('POST', '/upload', 'RecipeController', 'uploadImages');
$router->add('POST', '/recipes/save-generated', 'RecipeController', 'saveGeneratedRecipe');

// PAYMENTS & TOKEN PURCHASES
$router->add('GET', '/payments/config', 'PaymentController', 'getPaymentConfig');
$router->add('POST', '/payments/create-checkout-session', 'PaymentController', 'createCheckoutSession');
$router->add('POST', '/payments/verify-session', 'PaymentController', 'verifyCheckoutSession');
$router->add('POST', '/payments/webhook', 'PaymentController', 'handleWebhook');

// MEAL PLANNER
$router->add('GET', '/meal-plans', 'MealPlanController', 'getMealPlans');
$router->add('GET', '/meal-plans/recipes', 'MealPlanController', 'getPlannableRecipes');
$router->add('POST', '/meal-plans', 'MealPlanController', 'addMealPlanEntry');
$router->add('PUT', '/meal-plans', 'MealPlanController', 'updateMealPlanEntry');
$router->add('DELETE', '/meal-plans', 'MealPlanController', 'deleteMealPlanEntry');

// RECENT CHATS (30-DAY DATABASE STORAGE)
$router->add('GET', '/chats', 'ChatController', 'getRecentChats');
$router->add('POST', '/chats', 'ChatController', 'saveChatSession');
$router->add('DELETE', '/chats', 'ChatController', 'deleteChatSession');

return $router;