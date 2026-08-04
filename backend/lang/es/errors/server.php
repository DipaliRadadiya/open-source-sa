<?php

return [
    // Shown when a server operation lost a race for a system lock and never
    // started. The answer is "try again", not "something is wrong".
    'busy' => 'El servidor está ocupado con otra tarea del sistema (puede haber una instalación o actualización de paquetes en curso). No se cambió nada: inténtalo de nuevo en un momento.',
];
