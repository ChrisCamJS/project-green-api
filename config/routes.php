<?php
use App\Router;

$router = new Router();



// CORE RECIPE ENDPOINTS 
$router->add('GET', '/recipes', 'RecipeController', 'getAllRecipes');
$router->add('POST', '/recipes', 'RecipeController', 'createRecipe'); 
$router->add('GET', '/recipes/single', 'RecipeController', 'getRecipeById');
$router->add('GET', '/recipes/lineage', 'RecipeController', 'getRecipeLineage');

// AUTHENTICATION & USERS
$router->add('POST', '/login', 'AuthController', 'login');
$router->add('POST', '/register', 'AuthController', 'register');
$router->add('POST', '/verify-email', 'AuthController', 'verifyEmail');
$router->add('POST', '/resend-verification', 'AuthController', 'resendVerification');
$router->add('POST', '/logout', 'AuthController', 'logout');
$router->add('POST', '/users/deduct-token', 'AuthController', 'deductToken');
$router->add('GET', '/admin/invites', 'AdminController', 'getInviteCodes');
$router->add('POST', '/admin/invites', 'AdminController', 'generateInviteCode');
$router->add('PUT', '/admin/invites/toggle', 'AdminController', 'toggleInviteCode');
$router->add('GET', '/admin/recipes', 'AdminController', 'getAdminRecipes');
$router->add('PUT', '/admin/recipes', 'AdminController', 'updateAdminRecipes');
$router->add('GET', '/admin/emma-context', 'AdminController', 'getAdminEmmaContext');
$router->add('PUT', '/admin/emma-context', 'AdminController', 'saveAdminEmmaContext');
$router->add('DELETE', '/admin/emma-context', 'AdminController', 'deleteAdminEmmaContext');
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
$router->add('POST', '/recipes/photos', 'RecipeController', 'addRecipePhotos');
$router->add('PUT', '/recipes/primary-photo', 'RecipeController', 'setPrimaryPhoto');
$router->add('DELETE', '/recipes/photos', 'RecipeController', 'deleteRecipePhoto');
$router->add('PUT', '/recipes/title', 'RecipeController', 'updateRecipeTitle');
$router->add('POST', '/recipes/calculate-nutrition', 'RecipeController', 'calculateNutrition');
$router->add('POST', '/recipes/create-variant', 'RecipeController', 'createRecipeVariant');
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

// AI PROXY (KEEPS GEMINI API KEY SERVER-SIDE)
$router->add('POST', '/ai/chat', 'AiController', 'chat');
$router->add('POST', '/ai/image', 'AiController', 'generateImage');
$router->add('POST', '/ai/live-token', 'AiController', 'createLiveToken');
$router->add('POST', '/ai/google-drive-fetch', 'AiController', 'fetchGoogleDriveFile');

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
$router->add('GET', '/meal-plans/usda-search', 'MealPlanController', 'searchUsdaFoods');
$router->add('POST', '/meal-plans/copy-range', 'MealPlanController', 'copyMealPlanRange');
$router->add('POST', '/meal-plans/share', 'MealPlanController', 'createSharedMealPlan');
$router->add('GET', '/meal-plans/share', 'MealPlanController', 'getSharedMealPlan');
$router->add('POST', '/meal-plans/import-share', 'MealPlanController', 'importSharedMealPlan');

// RECENT CHATS (30-DAY DATABASE STORAGE)
$router->add('GET', '/chats', 'ChatController', 'getRecentChats');
$router->add('POST', '/chats', 'ChatController', 'saveChatSession');
$router->add('DELETE', '/chats', 'ChatController', 'deleteChatSession');

// NOTIFICATIONS
$router->add('GET', '/notifications', 'NotificationController', 'getNotifications');
$router->add('PUT', '/notifications/read', 'NotificationController', 'markRead');
$router->add('DELETE', '/notifications', 'NotificationController', 'deleteNotification');

// FITNESS ACTIVITIES & ACHIEVEMENTS
$router->add('GET', '/fitness-activities', 'FitnessController', 'getActivities');
$router->add('POST', '/fitness-activities', 'FitnessController', 'addActivity');
$router->add('DELETE', '/fitness-activities', 'FitnessController', 'deleteActivity');
$router->add('GET', '/achievements', 'AchievementController', 'getMyAchievements');

// RECIPE STORIES (USER BLOG POSTS & SOCIAL SHARING)
$router->add('GET', '/admin/stories', 'StoryController', 'getAdminStories');
$router->add('GET', '/stories', 'StoryController', 'getStories');
$router->add('GET', '/stories/single', 'StoryController', 'getStoryBySlug');
$router->add('POST', '/stories', 'StoryController', 'createStory');
$router->add('PUT', '/stories', 'StoryController', 'updateStory');
$router->add('DELETE', '/stories', 'StoryController', 'deleteStory');
$router->add('POST', '/stories/upload-image', 'StoryController', 'uploadStoryImage');
$router->add('POST', '/stories/like', 'StoryController', 'toggleStoryLike');

// CONTACT FORM (DIRECT EMAIL, NO DATABASE STORAGE)
$router->add('POST', '/contact', 'ContactController', 'submit');

return $router;