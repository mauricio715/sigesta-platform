"""Prueba manual contra Groq y Laravel REALES (consume cuota de Groq).

Requisitos: Laravel corriendo, .env completo (GROQ_API_KEY, IA_SERVICE_TOKEN...).
Uso:  python scripts/probar_en_vivo.py "¿Qué lotes de leche debo consumir primero?"
"""

from __future__ import annotations

import asyncio
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from groq import AsyncGroq  # noqa: E402

from config import get_settings  # noqa: E402
from services.groq_service import AsistenteIA  # noqa: E402
from services.laravel_client import LaravelClient  # noqa: E402

PREGUNTA_POR_DEFECTO = (
    "¿Qué lotes de lácteos debo consumir primero para la producción de hoy según FEFO "
    "y cuáles están cerca de vencer?"
)


async def main() -> None:
    settings = get_settings()
    pregunta = " ".join(sys.argv[1:]) or PREGUNTA_POR_DEFECTO
    laravel = LaravelClient(settings.laravel_api_base_url, settings.ia_service_token)

    try:
        asistente = AsistenteIA(laravel, AsyncGroq(api_key=settings.groq_api_key), modelo=settings.groq_model)
        resultado = await asistente.responder(pregunta, usuario={"name": "Prueba manual", "role": "admin"})
    finally:
        await laravel.cerrar()

    print(f"\nPregunta: {pregunta}\n")
    print("Herramientas:", ", ".join(f"{h.nombre}{'' if h.exito else ' (falló)'}" for h in resultado.herramientas_usadas) or "ninguna")
    print(f"Iteraciones: {resultado.iteraciones} · Modelo: {resultado.modelo}\n")
    print(resultado.respuesta)


if __name__ == "__main__":
    asyncio.run(main())
