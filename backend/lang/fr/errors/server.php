<?php

return [
    // Shown when a server operation lost a race for a system lock and never
    // started. The answer is "try again", not "something is wrong".
    'busy' => 'Le serveur est occupé par une autre tâche système (une installation ou une mise à jour de paquets est peut-être en cours). Rien n’a été modifié — réessayez dans un instant.',
];
