from fastapi import FastAPI, HTTPException, Depends, status
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from typing import List, Optional, Dict
from datetime import datetime, timedelta
from jose import JWTError, jwt
from passlib.context import CryptContext
from motor.motor_asyncio import AsyncIOMotorClient
from bson import ObjectId
from dotenv import load_dotenv
import os
from enum import Enum

# Lade Umgebungsvariablen
load_dotenv()

# FastAPI App
app = FastAPI(title="SMV Antragssystem", version="1.0.0")

# CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Security
SECRET_KEY = os.getenv("SECRET_KEY", "your-secret-key-hier-ersetzen")
ALGORITHM = "HS256"
ACCESS_TOKEN_EXPIRE_MINUTES = 30
MONGODB_URL = os.getenv("MONGODB_URL",
                        "mongodb+srv://<username>:<password>@<cluster-url>/<database>?retryWrites=true&w=majority")
DATABASE_NAME = "SMV"

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")
security = HTTPBearer()

# MongoDB Client für Atlas
client = AsyncIOMotorClient(MONGODB_URL,
                            serverSelectionTimeoutMS=5000,
                            connectTimeoutMS=10000,
                            socketTimeoutMS=10000)
database = client[DATABASE_NAME]
antraege_collection = database.antraege
benutzer_collection = database.benutzer
tags_collection = database.tags


# Enums
class AntragStatus(str, Enum):
    EINGEREICHT = "eingereicht"
    IN_BEARBEITUNG = "in_bearbeitung"
    GENEHMIGT = "genehmigt"
    ABGELEHNT = "abgelehnt"
    ZURUECKGESTELLT = "zurueckgestellt"


class BenachrichtigungsArt(str, Enum):
    LERNGRUPPENRAT = "lerngruppenrat"
    TEXTER = "texter"


class Phase(str, Enum):
    PHASE_5 = "Phase 5"
    PHASE_6 = "Phase 6"
    PHASE_7 = "Phase 7"
    PHASE_8 = "Phase 8"
    PHASE_9 = "Phase 9"
    PHASE_10 = "Phase 10"
    PHASE_11 = "Phase 11"
    PHASE_12 = "Phase 12"
    PHASE_13 = "Phase 13"


# Pydantic Models
class PyObjectId(ObjectId):
    @classmethod
    def __get_validators__(cls):
        yield cls.validate

    @classmethod
    def validate(cls, v):
        if not ObjectId.is_valid(v):
            raise ValueError("Invalid ObjectId")
        return ObjectId(v)

    @classmethod
    def __modify_schema__(cls, field_schema):
        field_schema.update(type="string")


class AntragErstellen(BaseModel):
    vorname: str = Field(..., min_length=1, max_length=100)
    nachname: str = Field(..., min_length=1, max_length=100)
    lerngruppe: str = Field(..., min_length=1, max_length=100)
    thema: str = Field(..., min_length=1, max_length=500)
    begründung: str = Field(..., min_length=1, max_length=2000)
    benachrichtigung_gewünscht: bool = True
    benachrichtigungs_art: Optional[BenachrichtigungsArt] = BenachrichtigungsArt.LERNGRUPPENRAT
    phase: Phase


class AntragAntwort(BaseModel):
    id: str = Field(alias="_id")
    vorname: str
    nachname: str
    lerngruppe: str
    thema: str
    begründung: str
    benachrichtigung_gewünscht: bool
    benachrichtigungs_art: Optional[BenachrichtigungsArt]
    phase: Phase
    status: AntragStatus
    tags: List[str] = []
    erstellt_am: datetime
    aktualisiert_am: datetime

    class Config:
        allow_population_by_field_name = True
        json_encoders = {ObjectId: str}


class AntragUpdate(BaseModel):
    status: Optional[AntragStatus] = None
    tags: Optional[List[str]] = None


class BenutzerLogin(BaseModel):
    benutzername: str
    passwort: str


class BenutzerErstellen(BaseModel):
    benutzername: str = Field(..., min_length=3, max_length=50)
    passwort: str = Field(..., min_length=6)
    ist_admin: bool = False


class Token(BaseModel):
    access_token: str
    token_type: str


class BenutzerInfo(BaseModel):
    id: str = Field(alias="_id")
    benutzername: str
    ist_admin: bool

    class Config:
        allow_population_by_field_name = True
        json_encoders = {ObjectId: str}


class TagModel(BaseModel):
    id: str = Field(alias="_id")
    name: str
    erstellt_am: datetime

    class Config:
        allow_population_by_field_name = True
        json_encoders = {ObjectId: str}


# Hilfsfunktionen
def verify_password(plain_password, hashed_password):
    return pwd_context.verify(plain_password, hashed_password)


def get_password_hash(password):
    return pwd_context.hash(password)


async def authenticate_user(username: str, password: str):
    user = await benutzer_collection.find_one({"benutzername": username})
    if not user or not verify_password(password, user["hashed_password"]):
        return False
    return user


def create_access_token(data: dict, expires_delta: Optional[timedelta] = None):
    to_encode = data.copy()
    if expires_delta:
        expire = datetime.utcnow() + expires_delta
    else:
        expire = datetime.utcnow() + timedelta(minutes=15)
    to_encode.update({"exp": expire})
    encoded_jwt = jwt.encode(to_encode, SECRET_KEY, algorithm=ALGORITHM)
    return encoded_jwt


async def get_current_user(credentials: HTTPAuthorizationCredentials = Depends(security)):
    credentials_exception = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Ungültige Anmeldedaten",
        headers={"WWW-Authenticate": "Bearer"},
    )
    try:
        token = credentials.credentials
        payload = jwt.decode(token, SECRET_KEY, algorithms=[ALGORITHM])
        username: str = payload.get("sub")
        if username is None:
            raise credentials_exception
    except JWTError:
        raise credentials_exception

    user = await benutzer_collection.find_one({"benutzername": username})
    if user is None:
        raise credentials_exception
    return user


async def get_admin_user(current_user: dict = Depends(get_current_user)):
    if not current_user.get("ist_admin"):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Administratorrechte erforderlich"
        )
    return current_user


# Startup Event - Erstelle Standard-Admin und Tags
@app.on_event("startup")
async def startup_event():
    # Erstelle Standard-Admin falls nicht vorhanden
    admin_exists = await benutzer_collection.find_one({"benutzername": "admin"})
    if not admin_exists:
        admin_user = {
            "benutzername": "admin",
            "hashed_password": get_password_hash("admin123"),
            "ist_admin": True,
            "erstellt_am": datetime.now()
        }
        await benutzer_collection.insert_one(admin_user)
        print("Standard-Admin erstellt: admin / admin123")

    # Erstelle Standard-SMV-Mitglied falls nicht vorhanden
    smv_exists = await benutzer_collection.find_one({"benutzername": "smv_mitglied"})
    if not smv_exists:
        smv_user = {
            "benutzername": "smv_mitglied",
            "hashed_password": get_password_hash("smv123"),
            "ist_admin": False,
            "erstellt_am": datetime.now()
        }
        await benutzer_collection.insert_one(smv_user)
        print("Standard-SMV-Mitglied erstellt: smv_mitglied / smv123")

    # Erstelle Standard-Tags falls nicht vorhanden
    standard_tags = ["Dringend", "Finanzierung", "Veranstaltung", "Regeländerung", "Sonstiges"]
    for tag_name in standard_tags:
        tag_exists = await tags_collection.find_one({"name": tag_name})
        if not tag_exists:
            await tags_collection.insert_one({
                "name": tag_name,
                "erstellt_am": datetime.now()
            })


# Endpoints

@app.post("/login", response_model=Token)
async def login(benutzer_daten: BenutzerLogin):
    """Login für SMV-Mitglieder und Administratoren"""
    user = await authenticate_user(benutzer_daten.benutzername, benutzer_daten.passwort)
    if not user:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Falscher Benutzername oder Passwort",
            headers={"WWW-Authenticate": "Bearer"},
        )
    access_token_expires = timedelta(minutes=ACCESS_TOKEN_EXPIRE_MINUTES)
    access_token = create_access_token(
        data={"sub": user["benutzername"]}, expires_delta=access_token_expires
    )
    return {"access_token": access_token, "token_type": "bearer"}


@app.get("/me", response_model=BenutzerInfo)
async def get_current_user_info(current_user: dict = Depends(get_current_user)):
    """Aktuelle Benutzerinformationen abrufen"""
    return BenutzerInfo(
        _id=str(current_user["_id"]),
        benutzername=current_user["benutzername"],
        ist_admin=current_user["ist_admin"]
    )


@app.post("/benutzer", response_model=BenutzerInfo)
async def benutzer_erstellen(benutzer: BenutzerErstellen, admin_user: dict = Depends(get_admin_user)):
    """Neuen Benutzer erstellen (nur Administratoren)"""
    # Prüfe ob Benutzername bereits existiert
    existing_user = await benutzer_collection.find_one({"benutzername": benutzer.benutzername})
    if existing_user:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Benutzername bereits vergeben"
        )

    neuer_benutzer = {
        "benutzername": benutzer.benutzername,
        "hashed_password": get_password_hash(benutzer.passwort),
        "ist_admin": benutzer.ist_admin,
        "erstellt_am": datetime.now()
    }

    result = await benutzer_collection.insert_one(neuer_benutzer)
    # ObjectId in einen String umwandeln, bevor wir es dem Modell übergeben
    neuer_benutzer["_id"] = str(result.inserted_id)

    return BenutzerInfo(**neuer_benutzer)


@app.post("/antraege", response_model=AntragAntwort)
async def antrag_erstellen(antrag: AntragErstellen):
    """Neuen Antrag erstellen (kein Login erforderlich)"""
    jetzt = datetime.now()

    neuer_antrag = {
        "vorname": antrag.vorname,
        "nachname": antrag.nachname,
        "lerngruppe": antrag.lerngruppe,
        "thema": antrag.thema,
        "begründung": antrag.begründung,
        "benachrichtigung_gewünscht": antrag.benachrichtigung_gewünscht,
        "benachrichtigungs_art": antrag.benachrichtigungs_art,
        "phase": antrag.phase,
        "status": AntragStatus.EINGEREICHT,
        "tags": [],
        "erstellt_am": jetzt,
        "aktualisiert_am": jetzt
    }

    result = await antraege_collection.insert_one(neuer_antrag)
    neuer_antrag["_id"] = str(result.inserted_id)
    return AntragAntwort(**neuer_antrag)


@app.get("/antraege", response_model=List[AntragAntwort])
async def alle_antraege_abrufen(
        status: Optional[AntragStatus] = None,
        phase: Optional[Phase] = None,
        tag: Optional[str] = None,
        skip: int = 0,
        limit: int = 100,
        current_user: dict = Depends(get_current_user)
):
    """Alle Anträge abrufen (Login erforderlich)"""
    query = {}

    # Filter aufbauen
    if status:
        query["status"] = status
    if phase:
        query["phase"] = phase
    if tag:
        query["tags"] = {"$in": [tag]}

    # Anträge aus der Datenbank abrufen
    cursor = antraege_collection.find(query).sort("erstellt_am", -1).skip(skip).limit(limit)
    antraege = await cursor.to_list(length=limit)

    # ObjectIds in Strings umwandeln, bevor wir die Modelle erstellen
    for antrag in antraege:
        antrag["_id"] = str(antrag["_id"])

    return [AntragAntwort(**antrag) for antrag in antraege]


@app.get("/antraege/{antrag_id}", response_model=AntragAntwort)
async def antrag_abrufen(antrag_id: str, current_user: dict = Depends(get_current_user)):
    """Einzelnen Antrag abrufen (Login erforderlich)"""
    if not ObjectId.is_valid(antrag_id):
        raise HTTPException(status_code=400, detail="Ungültige Antrag-ID")

    antrag = await antraege_collection.find_one({"_id": ObjectId(antrag_id)})
    if not antrag:
        raise HTTPException(status_code=404, detail="Antrag nicht gefunden")

    # ObjectId in einen String umwandeln, bevor wir das Modell erstellen
    antrag["_id"] = str(antrag["_id"])
    return AntragAntwort(**antrag)


@app.put("/antraege/{antrag_id}", response_model=AntragAntwort)
async def antrag_aktualisieren(
        antrag_id: str,
        update: AntragUpdate,
        current_user: dict = Depends(get_current_user)
):
    """Antrag aktualisieren - Status und Tags ändern (Login erforderlich)"""
    if not ObjectId.is_valid(antrag_id):
        raise HTTPException(status_code=400, detail="Ungültige Antrag-ID")

    antrag = await antraege_collection.find_one({"_id": ObjectId(antrag_id)})
    if not antrag:
        raise HTTPException(status_code=404, detail="Antrag nicht gefunden")

    update_data = {"aktualisiert_am": datetime.now()}

    # Sowohl Administratoren als auch normale Benutzer können Status und Tags ändern
    if update.status:
        update_data["status"] = update.status
    if update.tags is not None:
        update_data["tags"] = update.tags

    await antraege_collection.update_one(
        {"_id": ObjectId(antrag_id)},
        {"$set": update_data}
    )

    # Aktualisierten Antrag zurückgeben
    updated_antrag = await antraege_collection.find_one({"_id": ObjectId(antrag_id)})
    # ObjectId in einen String umwandeln, bevor wir das Modell erstellen
    updated_antrag["_id"] = str(updated_antrag["_id"])
    return AntragAntwort(**updated_antrag)


@app.delete("/antraege/{antrag_id}")
async def antrag_loeschen(antrag_id: str, admin_user: dict = Depends(get_admin_user)):
    """Antrag löschen (nur Administratoren)"""
    if not ObjectId.is_valid(antrag_id):
        raise HTTPException(status_code=400, detail="Ungültige Antrag-ID")

    result = await antraege_collection.delete_one({"_id": ObjectId(antrag_id)})
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Antrag nicht gefunden")

    return {"message": "Antrag erfolgreich gelöscht"}


@app.get("/tags", response_model=List[str])
async def verfuegbare_tags_abrufen(current_user: dict = Depends(get_current_user)):
    """Verfügbare Tags abrufen (Login erforderlich)"""
    cursor = tags_collection.find({}).sort("name", 1)
    tags = await cursor.to_list(length=1000)
    return [tag["name"] for tag in tags]


@app.post("/tags")
async def tag_hinzufuegen(tag_name: str, admin_user: dict = Depends(get_admin_user)):
    """Neuen Tag hinzufügen (nur Administratoren)"""
    # Prüfe ob Tag bereits existiert
    existing_tag = await tags_collection.find_one({"name": tag_name})
    if existing_tag:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Tag existiert bereits"
        )

    await tags_collection.insert_one({
        "name": tag_name,
        "erstellt_am": datetime.now()
    })
    return {"message": f"Tag '{tag_name}' hinzugefügt"}


@app.delete("/tags/{tag_name}")
async def tag_loeschen(tag_name: str, admin_user: dict = Depends(get_admin_user)):
    """Tag löschen (nur Administratoren)"""
    # Tag aus Sammlung löschen
    result = await tags_collection.delete_one({"name": tag_name})

    # Tag aus allen Anträgen entfernen
    await antraege_collection.update_many(
        {"tags": tag_name},
        {
            "$pull": {"tags": tag_name},
            "$set": {"aktualisiert_am": datetime.now()}
        }
    )

    if result.deleted_count == 0:
        return {"message": f"Tag '{tag_name}' nicht gefunden"}

    return {"message": f"Tag '{tag_name}' gelöscht"}


@app.get("/statistiken")
async def statistiken_abrufen(current_user: dict = Depends(get_current_user)):
    """Statistiken über Anträge abrufen (Login erforderlich)"""
    # Total Anträge
    total_anträge = await antraege_collection.count_documents({})

    # Status Statistik
    status_pipeline = [
        {"$group": {"_id": "$status", "count": {"$sum": 1}}},
        {"$sort": {"_id": 1}}
    ]
    status_cursor = antraege_collection.aggregate(status_pipeline)
    status_stats = {doc["_id"]: doc["count"] async for doc in status_cursor}

    # Phase Statistik
    phase_pipeline = [
        {"$group": {"_id": "$phase", "count": {"$sum": 1}}},
        {"$sort": {"_id": 1}}
    ]
    phase_cursor = antraege_collection.aggregate(phase_pipeline)
    phase_stats = {doc["_id"]: doc["count"] async for doc in phase_cursor}

    # Verfügbare Tags
    cursor = tags_collection.find({}).sort("name", 1)
    verfügbare_tags = [tag["name"] async for tag in cursor]

    return {
        "total_anträge": total_anträge,
        "status_verteilung": status_stats,
        "phase_verteilung": phase_stats,
        "verfügbare_tags": verfügbare_tags
    }


# Health Check
@app.get("/health")
async def health_check():
    """Health Check Endpoint (kein Login erforderlich)"""
    try:
        # Teste MongoDB Verbindung
        await client.admin.command('ping')
        db_status = "connected"
    except Exception:
        db_status = "disconnected"

    return {
        "status": "OK",
        "timestamp": datetime.now(),
        "database": db_status
    }

app.mount("/static", StaticFiles(directory="static", html=True), name="static")

if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="0.0.0.0", port=8000)