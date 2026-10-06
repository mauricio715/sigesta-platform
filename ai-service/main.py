"""SI-GESTA · Microservicio de IA (FastAPI + Groq).

Ejecutar:  uvicorn main:app --reload --port 8001
"""

from __future__ import annotations

from contextlib import asynccontextmanager
from dataclasses import asdict
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from groq import APIConnectionError, APIError, AsyncGroq, RateLimitError

from config import cors_origins, get_settings
from schemas import ChatData, ChatRequest, ChatResponse
from services.groq_service import AsistenteIA
from services.laravel_client import LaravelApiError, LaravelClient


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    laravel = LaravelClient(
        settings.laravel_api_base_url,
        settings.ia_service_token,
        timeout=settings.timeout_laravel,
    )
    app.state.laravel = laravel
    app.state.asistente = AsistenteIA(
        laravel,
        AsyncGroq(api_key=settings.groq_api_key),
        modelo=settings.groq_model,
        max_iteraciones=settings.max_iteraciones_herramientas,
        max_caracteres_herramienta=settings.max_caracteres_herramienta,
    )
    yield
    await laravel.cerrar()


app = FastAPI(
    title="SI-GESTA · Asistente de Auditoría IA",
    version="1.0.0",
    description="Asistente conversacional de inocuidad (RAG + tool calling sobre la API de SI-GESTA).",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=cors_origins(),
    allow_credentials=False,
    allow_methods=["GET", "POST"],
    allow_headers=["Authorization", "Content-Type", "Accept"],
)


# ----------------------------------------------------------------------
#  Formato de errores igual al de la API de Laravel: { status, message }
# ----------------------------------------------------------------------


@app.exception_handler(HTTPException)
async def http_exception_handler(_: Request, exc: HTTPException) -> JSONResponse:
    return JSONResponse(status_code=exc.status_code, content={"status": "error", "message": exc.detail})


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(_: Request, exc: RequestValidationError) -> JSONResponse:
    errores: dict[str, list[str]] = {}
    for error in exc.errors():
        campo = ".".join(str(p) for p in error["loc"] if p != "body")
        errores.setdefault(campo or "body", []).append(error["msg"])
    return JSONResponse(
        status_code=422,
        content={"status": "error", "message": "Los datos enviados no son válidos.", "errors": errores},
    )


# ----------------------------------------------------------------------
#  Dependencias
# ----------------------------------------------------------------------


def get_laravel(request: Request) -> LaravelClient:
    return request.app.state.laravel


def get_asistente(request: Request) -> AsistenteIA:
    return request.app.state.asistente


async def usuario_actual(
    authorization: str | None = Header(default=None),
    laravel: LaravelClient = Depends(get_laravel),
) -> dict[str, Any]:
    """El usuario debe enviar SU token de SI-GESTA; se valida contra /auth/me.

    Así solo usuarios activos de la plataforma usan el asistente, aunque las
    consultas de datos se hagan con el token de servicio de solo lectura.
    """
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Falta el token de sesión de SI-GESTA (Authorization: Bearer ...).")

    try:
        return await laravel.verificar_usuario(authorization[7:].strip())
    except LaravelApiError as error:
        if error.status_code in (401, 403):
            raise HTTPException(status_code=error.status_code, detail=error.mensaje) from error
        raise HTTPException(status_code=503, detail="No se pudo validar la sesión con SI-GESTA.") from error


# ----------------------------------------------------------------------
#  Endpoints
# ----------------------------------------------------------------------


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok", "servicio": "sigesta-ai"}


@app.post("/api/v1/chat", response_model=ChatResponse)
async def chat(
    payload: ChatRequest,
    usuario: dict[str, Any] = Depends(usuario_actual),
    asistente: AsistenteIA = Depends(get_asistente),
) -> ChatResponse:
    try:
        resultado = await asistente.responder(
            payload.mensaje,
            [m.model_dump() for m in payload.historial],
            usuario,
        )
    except RateLimitError as error:
        raise HTTPException(status_code=429, detail="Límite de uso de Groq alcanzado. Intente en unos segundos.") from error
    except APIConnectionError as error:
        raise HTTPException(status_code=502, detail="No se pudo conectar con el servicio de IA (Groq).") from error
    except APIError as error:
        raise HTTPException(status_code=502, detail=f"El servicio de IA devolvió un error: {error}") from error

    return ChatResponse(
        message="Respuesta generada por el asistente de auditoría.",
        data=ChatData(
            respuesta=resultado.respuesta,
            herramientas_usadas=[asdict(h) for h in resultado.herramientas_usadas],
            modelo=resultado.modelo,
            iteraciones=resultado.iteraciones,
        ),
    )
