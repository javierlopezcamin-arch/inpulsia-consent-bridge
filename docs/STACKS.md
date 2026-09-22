# Guía de Stacks de Medición — Inpulsia

Qué herramientas instalar según tu proyecto, explicado en lenguaje claro.

---

## Glosario rápido (qué hace cada cosa)

**Complianz** — El banner de cookies. Pregunta al usuario si acepta o no. Obligatorio en Europa.

**Inpulsia Consent Bridge** — El "traductor". Cuando el usuario acepta o rechaza el banner, este plugin se lo dice a Google y Meta para que ellos sepan si pueden o no pueden trackear. Sin esto, Google ignora a tus usuarios europeos.

**Site Kit (Google)** — El plugin oficial de Google. Conecta tu WordPress con Google Analytics 4 y Search Console. Es la forma más fácil de tener GA4 en tu web.

**Google Analytics 4 (GA4)** — La herramienta de Google donde ves cuántas visitas tienes, de dónde vienen, qué páginas miran, cuánto tiempo se quedan, qué compran.

**Google Search Console** — Te dice por qué búsquedas en Google llega gente a tu web. Útil para SEO.

**PixelYourSite Free** — Plugin que mete el Pixel de Meta (Facebook/Instagram) en tu web. Mide visitas y eventos básicos para tus campañas de Meta Ads.

**PixelYourSite Pro** — La versión de pago. Añade Conversions API (CAPI), que envía los datos desde tu servidor en vez de desde el navegador. Recupera el 25-35% de datos que pierdes por adblockers.

**Stape** — Servicio profesional de tracking. Monta un servidor de Google Tag Manager en tu propio dominio. Máxima calidad de datos y atribución. De pago mensual.

**WooCommerce / WordPress** — Tu web o tienda. La base.

---

## Escenario 1 — Web corporativa básica

**Para quién:** páginas de servicios, agencias, despachos, blogs, portfolios. Sin venta online. Sin campañas de pago serias.

**Qué necesitas medir:** visitas, formularios de contacto, qué páginas funcionan mejor.

### Stack

| Componente | Función |
|---|---|
| Complianz | Banner de cookies legal |
| Inpulsia Consent Bridge | Comunica el consent a Google |
| Site Kit | Te conecta con GA4 y Search Console |

### Coste
Todo gratis.

### Resultado
Cumples RGPD. Mides visitas, formularios y SEO. Suficiente para el 80% de webs corporativas.

---

## Escenario 2 — Web corporativa avanzada

**Para quién:** webs con campañas serias en Meta Ads, Google Ads, generación de leads B2B, captación de clientes con presupuesto >500€/mes en publicidad.

**Qué necesitas medir:** visitas, formularios, leads cualificados, conversiones por campaña, ROI de cada anuncio.

### Stack

| Componente | Función |
|---|---|
| Complianz | Banner de cookies legal |
| Inpulsia Consent Bridge | Comunica el consent a Google y Meta |
| Site Kit | GA4 + Search Console |
| PixelYourSite Pro | Meta Pixel + CAPI + Google Ads conversions |

### Coste
~100€/año (PixelYourSite Pro).

### Resultado
Cumples RGPD. Mides todo. Tus campañas de Meta y Google Ads reciben datos limpios incluso de usuarios con adblocker. Optimización de campañas mucho mejor.

---

## Escenario 3 — Tienda online básica

**Para quién:** WooCommerce arrancando, vendes online pero sin invertir aún en publicidad fuerte (<500€/mes en ads).

**Qué necesitas medir:** visitas, productos vistos, añadidos al carrito, compras, ingresos.

### Stack

| Componente | Función |
|---|---|
| Complianz | Banner de cookies legal |
| Inpulsia Consent Bridge | Comunica el consent a Google y Meta |
| Site Kit | GA4 + Search Console |
| PixelYourSite Free | Meta Pixel + eventos eCommerce básicos |

### Coste
Todo gratis.

### Resultado
Tienes datos de ventas en GA4 (productos, ingresos, conversión). Tus campañas de Meta funcionan. Pierdes algo de señal por adblockers pero es asumible cuando estás empezando.

**Cuando subir el listón:** si gastas más de 500€/mes en Meta Ads o Google Ads, salta al siguiente escenario.

---

## Escenario 4 — Tienda online avanzada

**Para quién:** WooCommerce establecida, presupuesto serio en publicidad (>1000€/mes), múltiples canales (Meta + Google + TikTok), facturación >100k€/año.

**Qué necesitas medir:** todo el embudo eCommerce con máxima precisión, atribución multi-canal fiable, optimización de Smart Bidding y Advantage+.

### Stack nivel intermedio (PYS Pro)

| Componente | Función |
|---|---|
| Complianz | Banner de cookies legal |
| Inpulsia Consent Bridge | Comunica el consent a Google y Meta |
| Site Kit | GA4 + Search Console |
| PixelYourSite Pro | Meta + Google Ads + TikTok + GA4 con CAPI |

**Coste:** ~100€/año.

### Stack nivel pro (Stape sGTM)

| Componente | Función |
|---|---|
| Complianz | Banner de cookies legal |
| Inpulsia Consent Bridge | Comunica el consent a Google y Meta |
| Site Kit | Search Console (GA4 va por GTM) |
| Stape Conversion Tracking | Eventos eCommerce de WooCommerce al dataLayer |
| Stape sGTM | Servidor de tags propio en `gtm.tudominio.com` |

**Coste:** ~150-200€/año (Stape sGTM hosting).

### Resultado
Datos casi perfectos pese a adblockers, iOS y Safari. Cookies de primera parte (mejor atribución). Campañas optimizan mejor → más ventas con el mismo presupuesto. Cuando inviertes mucho en ads, el extra de calidad de dato se traduce en miles de euros más al mes.

---

## Resumen visual

```
                        Banner    Consent      Analítica       Conversiones
                        cookies   bridge       Google          Meta + Ads
                        ───────   ─────────    ──────────      ───────────
Web básica              Complianz Bridge       Site Kit        —
Web avanzada            Complianz Bridge       Site Kit        PYS Pro
Tienda básica           Complianz Bridge       Site Kit        PYS Free
Tienda avanzada (PYS)   Complianz Bridge       Site Kit        PYS Pro
Tienda avanzada (Stape) Complianz Bridge       Search Console  Stape + sGTM
```

---

## Reglas de decisión rápida

**¿Tienes tráfico europeo?** → Sí → Necesitas Complianz + Bridge sí o sí
**¿Vendes online?** → Sí → Necesitas eventos eCommerce (PYS o Stape)
**¿Gastas >500€/mes en ads?** → Sí → Salta a versión avanzada
**¿Gastas >2000€/mes en ads?** → Sí → Stape sGTM compensa el coste

---

## Lo que nunca cambia

En **todos** los escenarios necesitas:
1. **Complianz** (o equivalente) → legal
2. **Inpulsia Consent Bridge** → que Google y Meta respeten el consent

El resto se monta encima según necesidad.
