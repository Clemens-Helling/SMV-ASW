from flask import Flask, render_template, request, redirect, url_for
from flask_sqlalchemy import SQLAlchemy
import datetime
import os

app = Flask(__name__)

# Datenbank-URI von Umgebungsvariable lesen (für Render)
# Fallback auf SQLite für lokale Entwicklung
app.config['SQLALCHEMY_DATABASE_URI'] = os.environ.get('DATABASE_URL', 'sqlite:///antraege.db')
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
db = SQLAlchemy(app)

# WICHTIG: Erstelle die Datenbanktabellen beim Start der App, wenn sie nicht existieren.
# Dies ist eine einfache Methode, wenn der Zugriff auf die Shell nicht möglich ist.
# db.create_all() ist idempotent und erstellt Tabellen nur, wenn sie nicht existieren.
with app.app_context():
    db.create_all()

# Definition des Datenbankmodells (Entspricht einer Tabelle in der DB)
class Antrag(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    titel = db.Column(db.String(200), nullable=False)
    antragsteller = db.Column(db.String(100), nullable=False)
    datum = db.Column(db.Date, nullable=False, default=datetime.date.today)
    beschluss = db.Column(db.String(50), default='offen') # z.B. offen, angenommen, abgelehnt, vertagt
    status = db.Column(db.String(50), default='offen')   # z.B. offen, in Bearbeitung, abgeschlossen
    notizen = db.Column(db.Text, nullable=True)

    def __repr__(self):
        return f'<Antrag {self.id}: {self.titel}>'

@app.route('/')
def index():
    """Startseite - leitet direkt zur Antragsübersicht weiter"""
    return redirect(url_for('list_antraege'))

@app.route('/antraege')
def list_antraege():
    """Zeigt alle Anträge an, optional gefiltert"""
    status_filter = request.args.get('status')
    beschluss_filter = request.args.get('beschluss')

    query = Antrag.query

    if status_filter:
        query = query.filter_by(status=status_filter)
    if beschluss_filter:
        query = query.filter_by(beschluss=beschluss_filter)

    antraege = query.order_by(Antrag.datum.desc()).all()
    
    # Mögliche Filteroptionen für das Frontend
    alle_stati = sorted(list(set([a.status for a in Antrag.query.all()])))
    alle_beschluesse = sorted(list(set([a.beschluss for a in Antrag.query.all()])))


    return render_template('antraege.html', 
                           antraege=antraege,
                           aktiver_status=status_filter,
                           aktiver_beschluss=beschluss_filter,
                           verfuegbare_stati=alle_stati,
                           verfuegbare_beschluesse=alle_beschluesse)

@app.route('/antrag/neu', methods=['GET', 'POST'])
def add_antrag():
    """Formular zum Hinzufügen eines neuen Antrags"""
    if request.method == 'POST':
        titel = request.form['titel']
        antragsteller = request.form['antragsteller']
        datum_str = request.form['datum']
        try:
            datum = datetime.datetime.strptime(datum_str, '%Y-%m-%d').date()
        except ValueError:
            # Fehlerbehandlung für falsches Datumsformat
            datum = datetime.date.today() # Standardwert
            
        # Standardwerte für neue Anträge
        neuer_antrag = Antrag(
            titel=titel,
            antragsteller=antragsteller,
            datum=datum,
            beschluss='offen',
            status='offen'
        )
        db.session.add(neuer_antrag)
        db.session.commit()
        return redirect(url_for('list_antraege'))
    return render_template('form.html', antrag=None, action='neu') # antrag=None bedeutet: leeres Formular

@app.route('/antrag/bearbeiten/<int:antrag_id>', methods=['GET', 'POST'])
def edit_antrag(antrag_id):
    """Formular zum Bearbeiten eines bestehenden Antrags"""
    antrag = Antrag.query.get_or_404(antrag_id) # Holt den Antrag oder gibt 404 Fehler
    if request.method == 'POST':
        antrag.titel = request.form['titel']
        antrag.antragsteller = request.form['antragsteller']
        
        datum_str = request.form['datum']
        try:
            antrag.datum = datetime.datetime.strptime(datum_str, '%Y-%m-%d').date()
        except ValueError:
            pass # Behält das alte Datum bei Fehler
            
        antrag.beschluss = request.form['beschluss']
        antrag.status = request.form['status']
        antrag.notizen = request.form.get('notizen') # Optionales Feld

        db.session.commit()
        return redirect(url_for('list_antraege'))
    return render_template('form.html', antrag=antrag, action='bearbeiten')

@app.route('/antrag/loeschen/<int:antrag_id>', methods=['POST'])
def delete_antrag(antrag_id):
    """Löscht einen Antrag"""
    antrag = Antrag.query.get_or_404(antrag_id)
    db.session.delete(antrag)
    db.session.commit()
    return redirect(url_for('list_antraege'))

if __name__ == '__main__':
    # Lokaler Start: Die Datenbanktabellen werden erstellt, wenn sie nicht existieren.
    # Für Render wird dieser Block nicht direkt ausgeführt, aber der 'with app.app_context(): db.create_all()' oben schon.
    app.run(debug=True)