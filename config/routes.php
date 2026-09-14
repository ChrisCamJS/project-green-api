<?php
use App\Router;

$router = new Router();



// CORE RECIPE ENDPOINTS 
$router->add('GET', '/recipes', 'RecipeController', 'getAllRecipes');
$router->add('POST', '/recipes', 'RecipeController', 'createRecipe'); 
$router->add('GET', '/recipes/single', 'RecipeController', 'getRecipeById');

// AUTHENTICATION & USERS
$router->add('POST', '/login', 'AuthController', 'login');
$router->add('POST', '/logout', 'AuthController', 'logout');
$router->add('POST', '/users/deduct-token', 'AuthController', 'deductToken');
$router->add('GET', '/emergency-reset', 'AuthController', 'emergencyReset');

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

// EXTRAS & MEDIA
$router->add('POST', '/upload', 'RecipeController', 'uploadImages');
$router->add('POST', '/recipes/save-generated', 'RecipeController', 'saveGeneratedRecipe');

return $router;