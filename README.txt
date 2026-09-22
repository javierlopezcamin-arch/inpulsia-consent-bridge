===========================================
  INPULSIA CONSENT v2
  Consent Mode v2 + Medición sin fricción
===========================================

Versión: 2.7.0
Requisitos: WordPress 5.8+ · PHP 7.4+
Compatible con: Complianz · Google Site Kit · GTM · WooCommerce · Stape · Meta Pixel · TikTok Pixel


-------------------------------------------
¿QUÉ ES?
-------------------------------------------

Inpulsia Consent v2 es el puente que hace que tu banner de cookies
sirva de verdad ante Google, Meta y TikTok.

Tu banner (Complianz) pregunta al usuario si acepta cookies. Pero por
sí solo no le dice nada a Google ni a Meta. Resultado: incumples el
RGPD y pierdes datos en tus campañas.

Este plugin traduce la decisión del usuario al lenguaje de Google
Consent Mode v2 (obligatorio desde 2024 para Google Ads en Europa) y
la propaga a todas tus herramientas de medición, en tiempo real, en
todas las páginas y sin configuración técnica.


-------------------------------------------
PARA QUIÉN
-------------------------------------------

• Webs corporativas con tráfico europeo y campañas de pago.
• Tiendas WooCommerce que quieren medir ventas correctamente.
• Agencias que gestionan medición de varios clientes.


-------------------------------------------
FUNCIONALIDADES
-------------------------------------------

CUMPLIMIENTO Y CONSENT MODE v2
  · Inyecta el estado por defecto (denegado) antes que cualquier
    script de tracking.
  · Escucha el banner de Complianz (eventos DOM y dataLayer) y
    actualiza el consentimiento en el momento exacto de la decisión.
  · Restaura el consentimiento ya dado en TODAS las páginas
    siguientes (cookies de Complianz), incluida la de gracias.
  · Compatible con las cuatro categorías de Complianz:
    marketing, statistics, preferences y functional.
  · Región configurable (EEA o Global) y wait_for_update ajustable.

SINCRONIZACIÓN MULTIPLATAFORMA
  · Google (GA4, Ads) vía Consent Mode v2.
  · Meta Pixel (fbq consent grant/revoke).
  · TikTok Pixel (ttq enable/disable cookie).

MAPEO PERSONALIZABLE
  · Define qué categoría de cookies activa cada señal de Google.
  · Restauración a valores por defecto con un clic.

ECOMMERCE WOOCOMMERCE
  · Eventos GA4: view_item, view_item_list, select_item,
    add_to_cart, remove_from_cart, view_cart, begin_checkout, purchase.
  · Variaciones, categorías, cupones e ingresos.
  · Detecta otros trackers (PixelYourSite, GTM4WP, MonsterInsights,
    Stape) y avisa de posibles duplicados.

META PIXEL EVENTS
  · ViewContent, AddToCart, InitiateCheckout y Purchase.
  · Purchase incluye event_id (nº de pedido) para deduplicar con CAPI.
  · Solo actúa si window.fbq existe; no carga el píxel.

GOOGLE ENHANCED CONVERSIONS
  · En la página de gracias hashea email, teléfono, nombre y
    dirección (SHA-256) y los envía con gtag('set','user_data').
  · También expone user_data en el evento purchase del dataLayer y
    en window.icbUserData para su uso desde GTM.
  · Requiere activar "Conversiones mejoradas" en Google Ads.

SERVER-SIDE GTM (STAPE)
  · Carga GTM desde tu propio subdominio con test de conexión.

GESTIÓN DE CACHÉ
  · Purga automática al guardar ajustes, al actualizar y al activar.
  · Botón manual "Vaciar caché ahora".
  · Soporta WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache,
    SG Optimizer, Kinsta, Autoptimize, Breeze, Comet Cache y
    Cache Enabler, además de la object cache de WordPress.

PANEL DE CONTROL
  · Estado en vivo: consentimiento real tal y como lo ve Google.
  · Diagnóstico: checklist automático de entorno, dependencias,
    configuración, caché y runtime.
  · Salud: aceptación total, aceptación de marketing, tendencia
    diaria y páginas con más rechazos (datos anónimos, purga a 90d).
  · Auditor: informe imprimible para clientes o evidencia RGPD.


-------------------------------------------
INSTALACIÓN Y ACTUALIZACIÓN
-------------------------------------------

  1. Plugins → Añadir nuevo → Subir plugin → selecciona el ZIP.
     Si ya existe, WordPress ofrecerá "Reemplazar el actual".
  2. Asegúrate de tener Complianz y Site Kit (o GTM) activos.
  3. Tras actualizar, entra en Inpulsia Consent → Diagnóstico y
     confirma que todo está en verde. Si actualizaste por FTP,
     pulsa "Vaciar caché ahora" en Ajustes.

  La actualización conserva ajustes y datos de salud.


-------------------------------------------
ACTUALIZACIONES DESDE GITHUB
-------------------------------------------

  Desde la 2.7.0 el plugin se actualiza desde el panel de Plugins de
  WordPress, como cualquier plugin de wordpress.org, leyendo las
  releases del repositorio de GitHub.

  CONFIGURACIÓN (una vez por web — repo privado)
    Añade en wp-config.php, encima de "That's all, stop editing!":

      define( 'ICB_GITHUB_TOKEN', 'github_pat_xxxxxxxx' );

    El token es un "fine-grained personal access token" de GitHub:
    Settings → Developer settings → Fine-grained tokens → Generate.
    Repository access: solo este repo. Permissions → Contents:
    Read-only. Nada más. Puedes usar el mismo token en todas las webs.

  USO EN CADA WEB
    · Plugins → fila "Inpulsia Consent v2" → "Buscar actualización".
    · O Escritorio → Actualizaciones → "Comprobar de nuevo".
    · WordPress también comprueba solo dos veces al día, y admite
      actualizaciones automáticas ("Activar actualizaciones auto.").
    · Diagnóstico muestra el estado: al día / nueva versión / error.

  PUBLICAR UNA VERSIÓN NUEVA (en tu ordenador)
    1. Haz los cambios y añade "X.Y.Z — Título" al CHANGELOG.
    2. git commit
    3. bin/release.sh X.Y.Z
       (sube versión, crea tag y push; GitHub Actions publica la
       release con las notas del CHANGELOG y un ZIP instalable).


-------------------------------------------
CÓMO VERIFICAR QUE FUNCIONA
-------------------------------------------

  1. Ventana de incógnito → home → acepta el banner.
  2. Navega a otra página (o completa un formulario/checkout).
  3. DevTools → Network → filtra "collect" → en el hit de esa
     página el parámetro gcs debe ser G111 y npa=0.
  4. Con "Modo debug" activo, la consola muestra las líneas [ICB]
     con el estado aplicado.


-------------------------------------------
CHANGELOG
-------------------------------------------

2.7.0 — Actualizaciones desde GitHub
  · Nuevo actualizador: el plugin consulta las GitHub Releases y
    aparece como actualizable en el panel de Plugins.
  · Enlace "Buscar actualización" en la fila del plugin y soporte
    del botón "Comprobar de nuevo" de Escritorio → Actualizaciones.
  · Soporte de repo privado mediante ICB_GITHUB_TOKEN en wp-config.
  · Cabecera Update URI: wordpress.org nunca sobrescribe el plugin.
  · Nuevo check "Actualizaciones desde GitHub" en Diagnóstico.

2.6.0 — Pulido profesional
  · Detección de Complianz y Site Kit unificada en un único punto
    (ICB_Plugin::complianz_active / sitekit_active).
  · "Restaurar mapeo por defecto" ahora usa un redirect HTTP real
    (PRG) en lugar de un redirect JS a mitad de render.
  · Endpoint público del snapshot valida la forma exacta del payload.
  · uninstall.php elimina también la marca de purga de caché y los
    transients de rate-limit.
  · Diagnóstico reordenado: entorno → dependencias → configuración
    → caché → runtime.
  · Naming coherente ("Inpulsia Consent v2") en todos los avisos.
  · Cabecera del plugin completa (URI, licencia, Domain Path).

2.5.3 — Consentimiento persistido en páginas nuevas
  · El consentimiento dado en una página no se restauraba en las
    siguientes (p. ej. la de gracias tras un formulario o checkout):
    Complianz no vuelve a disparar eventos para una decisión ya
    tomada, y el plugin solo reaccionaba a eventos en vivo.
  · Ahora lee las cookies de Complianz (cmplz_marketing/statistics/
    preferences/functional) y hace una comprobación activa al cargar
    cada página, restaurando el estado antes del primer hit.
  · Verificado: gcs pasa de G100/npa=1 a G111/npa=0 en gracias.

2.5.2 — Estado de consent único
  · Todos los caminos (eventos DOM + dataLayer) alimentan un único
    estado autoritativo; apply() nunca lee un detail obsoleto.
  · Elimina el "flapeo" granted→denied→granted y los registros
    "parcial" falsos en un "aceptar todo".

2.5.1 — Registro de salud debounceado
  · Un "aceptar todo" (4 eventos de Complianz) se graba una vez con
    el estado final.

2.5.0 — Consistencia de métricas
  · Aceptaron/rechazaron/parcial derivan del mismo campo que el
    frontend calcula a partir de las señales reales.
  · Nueva métrica "Aceptaron marketing" (Salud y Auditor).
  · Validación del payload antes del rate-limit; purga de caché en
    la activación; limpieza de código muerto en WooCommerce.

2.3.x — Gestión de caché
  · Purga automática/manual con 10 sistemas soportados y check en
    Diagnóstico.

2.2.x — Fiabilidad del listener
  · Prioridad al array de categorías del evento sobre
    cmplz_get_all_consents(); intercepción del dataLayer con
    debounce; corrección del auditor.

2.1.0 — Meta Pixel events
  · ViewContent, AddToCart, InitiateCheckout, Purchase (event_id).

2.2.0 — Google Enhanced Conversions
  · Hash SHA-256 de datos del comprador en la página de gracias.

2.0.0 — Revisión general
  · readState compatible con Complianz Free (categoría functional),
    sin falsos rechazos en cmplz_run_after_all_scripts, logHealth
    por señales reales, rate-limits en reporter y log, purga
    semanal de la tabla de salud.


-------------------------------------------
SOPORTE
-------------------------------------------

  Desarrollado por Inpulsia · https://inpulsia.es
