\# SI-GESTA



\*\*Sistema Inteligente de Gestión de Trazabilidad y Calidad Alimentaria\*\*



Plataforma web para PYMES procesadoras y distribuidoras de alimentos de Cochabamba, Bolivia. Digitaliza el control de inventarios, recetas de producción, trazabilidad bidireccional de lotes, rotación FEFO y alertas sanitarias. Incluye un asistente de auditoría con inteligencia artificial.



Enfoque normativo: SENASAG, principios HACCP e ISO 22000.



> Proyecto Integrador — Ingeniería de Sistemas, UNIFRANZ 2026 · Caso de estudio: Brunette



\---



\## Características



\- \*\*Rotación FEFO\*\*: el sistema consume y despacha primero los lotes con vencimiento más próximo.

\- \*\*Recetas (BOM)\*\*: cada orden de producción descuenta los insumos de forma proporcional y genera un lote de producto terminado.

\- \*\*Trazabilidad bidireccional\*\*:

&#x20; - hacia atrás: de producto terminado a lotes de insumo y proveedores;

&#x20; - hacia adelante: de lote de insumo a productos fabricados y clientes.

\- \*\*Kardex inmutable\*\* con 8 tipos de movimiento. Las anulaciones se hacen con movimientos compensatorios dentro de un plazo de 24 h.

\- \*\*Alertas automáticas\*\*:

&#x20; - preventiva (30 % de vida útil restante);

&#x20; - crítica (lote vencido, con bloqueo de salida);

&#x20; - reorden (stock bajo el mínimo).

\- \*\*Reportes PDF y Excel\*\* con código de verificación SHA-256.

\- \*\*Asistente IA\*\* de auditoría en lenguaje natural (RAG + tool calling, solo lectura).

\- \*\*Control de acceso por roles (RBAC)\*\*: Administrador y Operador.



\## Arquitectura



Arquitectura desacoplada orientada a servicios (API-First, REST stateless).



```mermaid

flowchart LR

&#x20;   U\[Navegador<br/>React SPA] -- HTTP/JSON + token --> API\[API Core<br/>Laravel 11]

&#x20;   U -- consulta IA --> IA\[Microservicio IA<br/>FastAPI + Groq]

&#x20;   IA -- token solo-lectura --> API

&#x20;   API --> DB\[(MySQL 8<br/>sigesta\_db)]

&#x20;   IA -- LLM --> G\[Groq API<br/>llama-3.3-70b]

```



| Componente | Tecnología | Responsabilidad |

|---|---|---|

| Frontend | React 18, Vite, Tailwind CSS | Interfaz SPA responsive, dashboard de alertas |

| Backend core | Laravel 11 (PHP 8.3), Sanctum | Reglas de negocio, FEFO, BOM, Kardex, RBAC, reportes |

| Microservicio IA | Python 3.11+, FastAPI, Groq | Asistente conversacional con 7 herramientas de solo lectura |

| Base de datos | MySQL 8 | 16 tablas en 3FN, integridad ACID |



\## Estructura del repositorio



```

sigesta-platform/

├── backend/       # API REST Laravel 11

├── ai-service/    # Microservicio de IA (FastAPI)

└── frontend/      # Aplicación React (Vite)

```



\## Requisitos



\- PHP 8.3 con las extensiones `gd`, `zip`, `mbstring` y `pdo\_mysql`

\- Composer 2

\- MySQL 8

\- Python 3.11 o superior

\- Node.js 20 o superior

\- Una API key de \[Groq](https://console.groq.com)



\## Instalación



\### 1. Base de datos



```sql

CREATE DATABASE sigesta\_db CHARACTER SET utf8mb4 COLLATE utf8mb4\_unicode\_ci;

CREATE DATABASE sigesta\_db\_test CHARACTER SET utf8mb4 COLLATE utf8mb4\_unicode\_ci;

```



\### 2. Backend (Laravel)



```bash

cd backend

composer install

cp .env.example .env

php artisan key:generate

```



En `.env` configurar las credenciales de MySQL y `APP\_TIMEZONE=America/La\_Paz`. Después:



```bash

php artisan migrate --seed

php artisan serve            # http://localhost:8000

```



\### 3. Microservicio IA (FastAPI)



Generar el token de solo lectura que usará la IA:



```bash

cd backend

php artisan sigesta:token-ia     # copiar solo el valor del token

```



```bash

cd ai-service

python -m venv .venv

\# Windows: .venv\\Scripts\\activate   |   Linux/Mac: source .venv/bin/activate

pip install -r requirements.txt

cp .env.example .env

```



Completar en `ai-service/.env`:



```env

GROQ\_API\_KEY=tu\_api\_key\_de\_groq

GROQ\_MODEL=llama-3.3-70b-versatile

IA\_SERVICE\_TOKEN=token\_generado\_en\_el\_paso\_anterior

```



```bash

uvicorn main:app --reload --port 8001

```



\### 4. Frontend (React)



```bash

cd frontend

npm install

cp .env.example .env

npm run dev                  # http://localhost:5173

```



\### 5. Tareas programadas (alertas)



Las alertas de vencimiento y stock se generan con el scheduler de Laravel:



```bash

cd backend

php artisan schedule:work

```



En producción se usa un cron:



```

\* \* \* \* \* cd /ruta/backend \&\& php artisan schedule:run >> /dev/null 2>\&1

```



\## Datos de demostración



```bash

cd backend

php artisan migrate:fresh --seed --seeder=DemoSeeder

php artisan sigesta:token-ia     # volver a generar el token y actualizar ai-service/.env

```



El `DemoSeeder` simula 27 días de operación: compras, producción, ventas, mermas y alertas.



| Rol | Correo | Contraseña |

|---|---|---|

| Administrador | admin@sigesta.com | password |

| Operador | operador@sigesta.com | password |

| Operador | juan.mamani@sigesta.com | password |



Caso sugerido para la trazabilidad: lote de insumo \*\*LEC-LV-2609\*\* (lote de proveedor LV-2609).



> ⚠️ Estas credenciales son solo para demostración local. Deben cambiarse antes de cualquier despliegue.



\## Pruebas



```bash

\# Backend: 148 pruebas PHPUnit

cd backend

php artisan test



\# Microservicio IA: 13 pruebas pytest

cd ai-service

pip install -r requirements-dev.txt

pytest -q

```



\## Módulos



| Módulo | Descripción |

|---|---|

| Usuarios y roles | Autenticación Sanctum y RBAC (Administrador / Operador) |

| Catálogo | Insumos y productos terminados con unidad, vida útil y stock mínimo |

| Recetas (BOM) | Insumos y cantidades por rendimiento base |

| Compras / Entradas | Registro de lotes con fecha de vencimiento |

| Producción | Consumo FEFO automático y generación del lote de producto |

| Ventas / Despachos | Salida FEFO con bloqueo de lotes vencidos |

| Mermas y devoluciones | Bajas por desecho o vencimiento y devoluciones de clientes |

| Kardex | Historial inmutable de movimientos por usuario |

| Trazabilidad | Consulta hacia atrás y hacia adelante |

| Alertas | Preventiva, crítica y de reorden |

| Reportes | PDF y Excel con código de verificación |

| Asistente IA | Consultas en lenguaje natural sobre el inventario |



\## Seguridad



\- Contraseñas cifradas con Bcrypt.

\- Tokens Sanctum con expiración de 8 h.

\- Validación estricta con FormRequest y consultas parametrizadas (Eloquent) contra SQL Injection.

\- Control de acceso por rol mediante middleware.

\- El microservicio de IA usa un token con habilidad `solo-lectura` y no puede modificar datos.

\- Los archivos `.env` no se versionan; solo se incluyen los `.env.example`.



\## Autor



\*\*Mauricio Itian Calderón Hurtado\*\*

Ingeniería de Sistemas — UNIFRANZ, Cochabamba, 2026

Tutor: Ing. Christian Luis López Bravo

