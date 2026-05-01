export type TurkeyDistrict = {
  name: string
  normalizedName: string
}

export type TurkeyProvince = {
  name: string
  normalizedName: string
  plateCode: number
  latitude: number
  longitude: number
  districts: TurkeyDistrict[]
}

type TurkeyProvinceRaw = {
  name: string
  plateCode: number
  latitude: number
  longitude: number
  districts: string[]
}

const TURKISH_CHAR_MAP: Record<string, string> = {
  ç: 'c',
  Ç: 'c',
  ğ: 'g',
  Ğ: 'g',
  ı: 'i',
  I: 'i',
  İ: 'i',
  i: 'i',
  ö: 'o',
  Ö: 'o',
  ş: 's',
  Ş: 's',
  ü: 'u',
  Ü: 'u',
}

export function normalizeTurkishLocation(value: string | null | undefined): string {
  return String(value ?? '')
    .trim()
    .split('')
    .map((character) => TURKISH_CHAR_MAP[character] ?? character)
    .join('')
    .toLocaleLowerCase('tr-TR')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .replace(/[^a-z0-9]+/gu, '')
}

const TURKEY_PROVINCE_RAW: TurkeyProvinceRaw[] = [
  { name: 'Adana', plateCode: 1, latitude: 37.0, longitude: 35.3213, districts: [] },
  { name: 'Adıyaman', plateCode: 2, latitude: 37.7648, longitude: 38.2763, districts: [] },
  { name: 'Afyonkarahisar', plateCode: 3, latitude: 38.7567, longitude: 30.5433, districts: [] },
  { name: 'Ağrı', plateCode: 4, latitude: 39.7191, longitude: 43.0513, districts: [] },
  { name: 'Amasya', plateCode: 5, latitude: 40.6539, longitude: 35.8333, districts: [] },
  { name: 'Ankara', plateCode: 6, latitude: 39.9334, longitude: 32.8597, districts: [] },
  { name: 'Antalya', plateCode: 7, latitude: 36.8969, longitude: 30.7133, districts: [] },
  { name: 'Artvin', plateCode: 8, latitude: 41.183, longitude: 41.82, districts: [] },
  { name: 'Aydın', plateCode: 9, latitude: 37.856, longitude: 27.8416, districts: [] },
  { name: 'Balıkesir', plateCode: 10, latitude: 39.6484, longitude: 27.8826, districts: [] },
  { name: 'Bilecik', plateCode: 11, latitude: 40.1428, longitude: 29.9793, districts: [] },
  { name: 'Bingöl', plateCode: 12, latitude: 38.8854, longitude: 40.4989, districts: [] },
  { name: 'Bitlis', plateCode: 13, latitude: 38.3941, longitude: 42.1232, districts: [] },
  { name: 'Bolu', plateCode: 14, latitude: 40.735, longitude: 31.612, districts: [] },
  { name: 'Burdur', plateCode: 15, latitude: 37.7203, longitude: 30.2908, districts: [] },
  { name: 'Bursa', plateCode: 16, latitude: 40.1828, longitude: 29.0665, districts: [] },
  { name: 'Çanakkale', plateCode: 17, latitude: 40.1553, longitude: 26.4142, districts: [] },
  { name: 'Çankırı', plateCode: 18, latitude: 40.602, longitude: 33.6134, districts: [] },
  { name: 'Çorum', plateCode: 19, latitude: 40.5489, longitude: 34.9556, districts: [] },
  { name: 'Denizli', plateCode: 20, latitude: 37.7765, longitude: 29.0864, districts: [] },
  { name: 'Diyarbakır', plateCode: 21, latitude: 37.9144, longitude: 40.2306, districts: [] },
  { name: 'Edirne', plateCode: 22, latitude: 41.6771, longitude: 26.5557, districts: [] },
  { name: 'Elazığ', plateCode: 23, latitude: 38.6743, longitude: 39.2232, districts: [] },
  { name: 'Erzincan', plateCode: 24, latitude: 39.75, longitude: 39.49, districts: [] },
  { name: 'Erzurum', plateCode: 25, latitude: 39.9043, longitude: 41.2679, districts: [] },
  { name: 'Eskişehir', plateCode: 26, latitude: 39.7767, longitude: 30.5206, districts: [] },
  { name: 'Gaziantep', plateCode: 27, latitude: 37.0662, longitude: 37.3833, districts: [] },
  { name: 'Giresun', plateCode: 28, latitude: 40.9128, longitude: 38.3895, districts: [] },
  { name: 'Gümüşhane', plateCode: 29, latitude: 40.4602, longitude: 39.4814, districts: [] },
  { name: 'Hakkari', plateCode: 30, latitude: 37.5839, longitude: 43.7333, districts: [] },
  { name: 'Hatay', plateCode: 31, latitude: 36.2021, longitude: 36.1606, districts: [] },
  { name: 'Isparta', plateCode: 32, latitude: 37.7648, longitude: 30.5566, districts: [] },
  { name: 'Mersin', plateCode: 33, latitude: 36.8121, longitude: 34.6415, districts: [] },
  { name: 'İstanbul', plateCode: 34, latitude: 41.0082, longitude: 28.9784, districts: [] },
  { name: 'İzmir', plateCode: 35, latitude: 38.4237, longitude: 27.1428, districts: [] },
  { name: 'Kars', plateCode: 36, latitude: 40.5989, longitude: 43.0858, districts: [] },
  { name: 'Kastamonu', plateCode: 37, latitude: 41.3887, longitude: 33.7827, districts: [] },
  { name: 'Kayseri', plateCode: 38, latitude: 38.7312, longitude: 35.4787, districts: [] },
  { name: 'Kırklareli', plateCode: 39, latitude: 41.7351, longitude: 27.2257, districts: [] },
  { name: 'Kırşehir', plateCode: 40, latitude: 39.146, longitude: 34.16, districts: [] },
  { name: 'Kocaeli', plateCode: 41, latitude: 40.8533, longitude: 29.8815, districts: [] },
  { name: 'Konya', plateCode: 42, latitude: 37.8746, longitude: 32.4932, districts: [] },
  { name: 'Kütahya', plateCode: 43, latitude: 39.4208, longitude: 29.9833, districts: [] },
  { name: 'Malatya', plateCode: 44, latitude: 38.3552, longitude: 38.3095, districts: [] },
  { name: 'Manisa', plateCode: 45, latitude: 38.6191, longitude: 27.4289, districts: [] },
  { name: 'Kahramanmaraş', plateCode: 46, latitude: 37.5753, longitude: 36.9228, districts: [] },
  { name: 'Mardin', plateCode: 47, latitude: 37.3125, longitude: 40.736, districts: [] },
  { name: 'Muğla', plateCode: 48, latitude: 37.2153, longitude: 28.3636, districts: [] },
  { name: 'Muş', plateCode: 49, latitude: 38.9462, longitude: 41.7539, districts: [] },
  { name: 'Nevşehir', plateCode: 50, latitude: 38.6244, longitude: 34.714, districts: [] },
  { name: 'Niğde', plateCode: 51, latitude: 37.9662, longitude: 34.6798, districts: [] },
  { name: 'Ordu', plateCode: 52, latitude: 40.9862, longitude: 37.8797, districts: [] },
  { name: 'Rize', plateCode: 53, latitude: 41.0245, longitude: 40.5219, districts: [] },
  { name: 'Sakarya', plateCode: 54, latitude: 40.7731, longitude: 30.3948, districts: [] },
  { name: 'Samsun', plateCode: 55, latitude: 41.2867, longitude: 36.33, districts: [] },
  { name: 'Siirt', plateCode: 56, latitude: 37.9333, longitude: 41.95, districts: [] },
  { name: 'Sinop', plateCode: 57, latitude: 42.0268, longitude: 35.1551, districts: [] },
  { name: 'Sivas', plateCode: 58, latitude: 39.7477, longitude: 37.0179, districts: [] },
  { name: 'Tekirdağ', plateCode: 59, latitude: 40.978, longitude: 27.511, districts: [] },
  { name: 'Tokat', plateCode: 60, latitude: 40.3167, longitude: 36.55, districts: [] },
  { name: 'Trabzon', plateCode: 61, latitude: 41.0015, longitude: 39.7178, districts: [] },
  { name: 'Tunceli', plateCode: 62, latitude: 39.1061, longitude: 39.5486, districts: [] },
  { name: 'Şanlıurfa', plateCode: 63, latitude: 37.1674, longitude: 38.7955, districts: [] },
  { name: 'Uşak', plateCode: 64, latitude: 38.6743, longitude: 29.4058, districts: [] },
  { name: 'Van', plateCode: 65, latitude: 38.4891, longitude: 43.4089, districts: [] },
  { name: 'Yozgat', plateCode: 66, latitude: 39.8197, longitude: 34.8147, districts: [] },
  { name: 'Zonguldak', plateCode: 67, latitude: 41.4564, longitude: 31.7987, districts: [] },
  { name: 'Aksaray', plateCode: 68, latitude: 38.3687, longitude: 34.037, districts: [] },
  { name: 'Bayburt', plateCode: 69, latitude: 40.2552, longitude: 40.2249, districts: [] },
  { name: 'Karaman', plateCode: 70, latitude: 37.1759, longitude: 33.2287, districts: [] },
  { name: 'Kırıkkale', plateCode: 71, latitude: 39.8468, longitude: 33.5153, districts: [] },
  { name: 'Batman', plateCode: 72, latitude: 37.8812, longitude: 41.1351, districts: [] },
  { name: 'Şırnak', plateCode: 73, latitude: 37.4187, longitude: 42.4918, districts: [] },
  { name: 'Bartın', plateCode: 74, latitude: 41.5811, longitude: 32.461, districts: [] },
  { name: 'Ardahan', plateCode: 75, latitude: 41.1105, longitude: 42.7022, districts: [] },
  { name: 'Iğdır', plateCode: 76, latitude: 39.888, longitude: 44.004, districts: [] },
  { name: 'Yalova', plateCode: 77, latitude: 40.655, longitude: 29.276, districts: [] },
  { name: 'Karabük', plateCode: 78, latitude: 41.2061, longitude: 32.6204, districts: [] },
  { name: 'Kilis', plateCode: 79, latitude: 36.7184, longitude: 37.1212, districts: [] },
  { name: 'Osmaniye', plateCode: 80, latitude: 37.213, longitude: 36.176, districts: [] },
  { name: 'Düzce', plateCode: 81, latitude: 40.8438, longitude: 31.1565, districts: [] },
]

const TURKEY_DISTRICTS_BY_PROVINCE: Record<string, string[]> = {
  Adana: ['Aladağ', 'Ceyhan', 'Çukurova', 'Feke', 'İmamoğlu', 'Karaisalı', 'Karataş', 'Kozan', 'Pozantı', 'Saimbeyli', 'Sarıçam', 'Seyhan', 'Tufanbeyli', 'Yumurtalık', 'Yüreğir'],
  Adıyaman: ['Besni', 'Çelikhan', 'Gerger', 'Gölbaşı', 'Kahta', 'Merkez', 'Samsat', 'Sincik', 'Tut'],
  Afyonkarahisar: ['Başmakçı', 'Bayat', 'Bolvadin', 'Çay', 'Çobanlar', 'Dazkırı', 'Dinar', 'Emirdağ', 'Evciler', 'Hocalar', 'İhsaniye', 'İscehisar', 'Kızılören', 'Merkez', 'Sandıklı', 'Sinanpaşa', 'Sultandağı', 'Şuhut'],
  Ağrı: ['Diyadin', 'Doğubayazıt', 'Eleşkirt', 'Hamur', 'Merkez', 'Patnos', 'Taşlıçay', 'Tutak'],
  Amasya: ['Göynücek', 'Gümüşhacıköy', 'Hamamözü', 'Merkez', 'Merzifon', 'Suluova', 'Taşova'],
  Ankara: ['Altındağ', 'Ayaş', 'Bala', 'Beypazarı', 'Çamlıdere', 'Çankaya', 'Çubuk', 'Elmadağ', 'Etimesgut', 'Evren', 'Gölbaşı', 'Güdül', 'Haymana', 'Kahramankazan', 'Kalecik', 'Keçiören', 'Kızılcahamam', 'Mamak', 'Nallıhan', 'Polatlı', 'Pursaklar', 'Sincan', 'Şereflikoçhisar', 'Yenimahalle'],
  Antalya: ['Akseki', 'Aksu', 'Alanya', 'Demre', 'Döşemealtı', 'Elmalı', 'Finike', 'Gazipaşa', 'Gündoğmuş', 'İbradı', 'Kaş', 'Kemer', 'Kepez', 'Konyaaltı', 'Korkuteli', 'Kumluca', 'Manavgat', 'Muratpaşa', 'Serik'],
  Artvin: ['Ardanuç', 'Arhavi', 'Borçka', 'Hopa', 'Kemalpaşa', 'Merkez', 'Murgul', 'Şavşat', 'Yusufeli'],
  Aydın: ['Bozdoğan', 'Buharkent', 'Çine', 'Didim', 'Efeler', 'Germencik', 'İncirliova', 'Karacasu', 'Karpuzlu', 'Koçarlı', 'Köşk', 'Kuşadası', 'Kuyucak', 'Nazilli', 'Söke', 'Sultanhisar', 'Yenipazar'],
  Balıkesir: ['Altıeylül', 'Ayvalık', 'Balya', 'Bandırma', 'Bigadiç', 'Burhaniye', 'Dursunbey', 'Edremit', 'Erdek', 'Gömeç', 'Gönen', 'Havran', 'İvrindi', 'Karesi', 'Kepsut', 'Manyas', 'Marmara', 'Savaştepe', 'Sındırgı', 'Susurluk'],
  Bilecik: ['Bozüyük', 'Gölpazarı', 'İnhisar', 'Merkez', 'Osmaneli', 'Pazaryeri', 'Söğüt', 'Yenipazar'],
  Bingöl: ['Adaklı', 'Genç', 'Karlıova', 'Kiğı', 'Merkez', 'Solhan', 'Yayladere', 'Yedisu'],
  Bitlis: ['Adilcevaz', 'Ahlat', 'Güroymak', 'Hizan', 'Merkez', 'Mutki', 'Tatvan'],
  Bolu: ['Dörtdivan', 'Gerede', 'Göynük', 'Kıbrıscık', 'Mengen', 'Merkez', 'Mudurnu', 'Seben', 'Yeniçağa'],
  Burdur: ['Ağlasun', 'Altınyayla', 'Bucak', 'Çavdır', 'Çeltikçi', 'Gölhisar', 'Karamanlı', 'Kemer', 'Merkez', 'Tefenni', 'Yeşilova'],
  Bursa: ['Büyükorhan', 'Gemlik', 'Gürsu', 'Harmancık', 'İnegöl', 'İznik', 'Karacabey', 'Keles', 'Kestel', 'Mudanya', 'Mustafakemalpaşa', 'Nilüfer', 'Orhaneli', 'Orhangazi', 'Osmangazi', 'Yenişehir', 'Yıldırım'],
  Çanakkale: ['Ayvacık', 'Bayramiç', 'Biga', 'Bozcaada', 'Çan', 'Eceabat', 'Ezine', 'Gelibolu', 'Gökçeada', 'Lapseki', 'Merkez', 'Yenice'],
  Çankırı: ['Atkaracalar', 'Bayramören', 'Çerkeş', 'Eldivan', 'Ilgaz', 'Kızılırmak', 'Korgun', 'Kurşunlu', 'Merkez', 'Orta', 'Şabanözü', 'Yapraklı'],
  Çorum: ['Alaca', 'Bayat', 'Boğazkale', 'Dodurga', 'İskilip', 'Kargı', 'Laçin', 'Mecitözü', 'Merkez', 'Oğuzlar', 'Ortaköy', 'Osmancık', 'Sungurlu', 'Uğurludağ'],
  Denizli: ['Acıpayam', 'Babadağ', 'Baklan', 'Bekilli', 'Beyağaç', 'Bozkurt', 'Buldan', 'Çal', 'Çameli', 'Çardak', 'Çivril', 'Güney', 'Honaz', 'Kale', 'Merkezefendi', 'Pamukkale', 'Sarayköy', 'Serinhisar', 'Tavas'],
  Diyarbakır: ['Bağlar', 'Bismil', 'Çermik', 'Çınar', 'Çüngüş', 'Dicle', 'Eğil', 'Ergani', 'Hani', 'Hazro', 'Kayapınar', 'Kocaköy', 'Kulp', 'Lice', 'Silvan', 'Sur', 'Yenişehir'],
  Edirne: ['Enez', 'Havsa', 'İpsala', 'Keşan', 'Lalapaşa', 'Meriç', 'Merkez', 'Süloğlu', 'Uzunköprü'],
  Elazığ: ['Ağın', 'Alacakaya', 'Arıcak', 'Baskil', 'Karakoçan', 'Keban', 'Kovancılar', 'Maden', 'Merkez', 'Palu', 'Sivrice'],
  Erzincan: ['Çayırlı', 'İliç', 'Kemah', 'Kemaliye', 'Merkez', 'Otlukbeli', 'Refahiye', 'Tercan', 'Üzümlü'],
  Erzurum: ['Aşkale', 'Aziziye', 'Çat', 'Hınıs', 'Horasan', 'İspir', 'Karaçoban', 'Karayazı', 'Köprüköy', 'Narman', 'Oltu', 'Olur', 'Palandöken', 'Pasinler', 'Pazaryolu', 'Şenkaya', 'Tekman', 'Tortum', 'Uzundere', 'Yakutiye'],
  Eskişehir: ['Alpu', 'Beylikova', 'Çifteler', 'Günyüzü', 'Han', 'İnönü', 'Mahmudiye', 'Mihalgazi', 'Mihalıççık', 'Odunpazarı', 'Sarıcakaya', 'Seyitgazi', 'Sivrihisar', 'Tepebaşı'],
  Gaziantep: ['Araban', 'İslahiye', 'Karkamış', 'Nizip', 'Nurdağı', 'Oğuzeli', 'Şahinbey', 'Şehitkamil', 'Yavuzeli'],
  Giresun: ['Alucra', 'Bulancak', 'Çamoluk', 'Çanakçı', 'Dereli', 'Doğankent', 'Espiye', 'Eynesil', 'Görele', 'Güce', 'Keşap', 'Merkez', 'Piraziz', 'Şebinkarahisar', 'Tirebolu', 'Yağlıdere'],
  Gümüşhane: ['Kelkit', 'Köse', 'Kürtün', 'Merkez', 'Şiran', 'Torul'],
  Hakkari: ['Çukurca', 'Derecik', 'Merkez', 'Şemdinli', 'Yüksekova'],
  Hatay: ['Altınözü', 'Antakya', 'Arsuz', 'Belen', 'Defne', 'Dörtyol', 'Erzin', 'Hassa', 'İskenderun', 'Kırıkhan', 'Kumlu', 'Payas', 'Reyhanlı', 'Samandağ', 'Yayladağı'],
  Isparta: ['Aksu', 'Atabey', 'Eğirdir', 'Gelendost', 'Gönen', 'Keçiborlu', 'Merkez', 'Senirkent', 'Sütçüler', 'Şarkikaraağaç', 'Uluborlu', 'Yalvaç', 'Yenişarbademli'],
  Mersin: ['Akdeniz', 'Anamur', 'Aydıncık', 'Bozyazı', 'Çamlıyayla', 'Erdemli', 'Gülnar', 'Mezitli', 'Mut', 'Silifke', 'Tarsus', 'Toroslar', 'Yenişehir'],
  İstanbul: ['Adalar', 'Arnavutköy', 'Ataşehir', 'Avcılar', 'Bağcılar', 'Bahçelievler', 'Bakırköy', 'Başakşehir', 'Bayrampaşa', 'Beşiktaş', 'Beykoz', 'Beylikdüzü', 'Beyoğlu', 'Büyükçekmece', 'Çatalca', 'Çekmeköy', 'Esenler', 'Esenyurt', 'Eyüpsultan', 'Fatih', 'Gaziosmanpaşa', 'Güngören', 'Kadıköy', 'Kağıthane', 'Kartal', 'Küçükçekmece', 'Maltepe', 'Pendik', 'Sancaktepe', 'Sarıyer', 'Silivri', 'Sultanbeyli', 'Sultangazi', 'Şile', 'Şişli', 'Tuzla', 'Ümraniye', 'Üsküdar', 'Zeytinburnu'],
  İzmir: ['Aliağa', 'Balçova', 'Bayındır', 'Bayraklı', 'Bergama', 'Beydağ', 'Bornova', 'Buca', 'Çeşme', 'Çiğli', 'Dikili', 'Foça', 'Gaziemir', 'Güzelbahçe', 'Karabağlar', 'Karaburun', 'Karşıyaka', 'Kemalpaşa', 'Kınık', 'Kiraz', 'Konak', 'Menderes', 'Menemen', 'Narlıdere', 'Ödemiş', 'Seferihisar', 'Selçuk', 'Tire', 'Torbalı', 'Urla'],
  Kars: ['Akyaka', 'Arpaçay', 'Digor', 'Kağızman', 'Merkez', 'Sarıkamış', 'Selim', 'Susuz'],
  Kastamonu: ['Abana', 'Ağlı', 'Araç', 'Azdavay', 'Bozkurt', 'Cide', 'Çatalzeytin', 'Daday', 'Devrekani', 'Doğanyurt', 'Hanönü', 'İhsangazi', 'İnebolu', 'Küre', 'Merkez', 'Pınarbaşı', 'Seydiler', 'Şenpazar', 'Taşköprü', 'Tosya'],
  Kayseri: ['Akkışla', 'Bünyan', 'Develi', 'Felahiye', 'Hacılar', 'İncesu', 'Kocasinan', 'Melikgazi', 'Özvatan', 'Pınarbaşı', 'Sarıoğlan', 'Sarız', 'Talas', 'Tomarza', 'Yahyalı', 'Yeşilhisar'],
  Kırklareli: ['Babaeski', 'Demirköy', 'Kofçaz', 'Lüleburgaz', 'Merkez', 'Pehlivanköy', 'Pınarhisar', 'Vize'],
  Kırşehir: ['Akçakent', 'Akpınar', 'Boztepe', 'Çiçekdağı', 'Kaman', 'Merkez', 'Mucur'],
  Kocaeli: ['Başiskele', 'Çayırova', 'Darıca', 'Derince', 'Dilovası', 'Gebze', 'Gölcük', 'İzmit', 'Kandıra', 'Karamürsel', 'Kartepe', 'Körfez'],
  Konya: ['Ahırlı', 'Akören', 'Akşehir', 'Altınekin', 'Beyşehir', 'Bozkır', 'Cihanbeyli', 'Çeltik', 'Çumra', 'Derbent', 'Derebucak', 'Doğanhisar', 'Emirgazi', 'Ereğli', 'Güneysınır', 'Hadim', 'Halkapınar', 'Hüyük', 'Ilgın', 'Kadınhanı', 'Karapınar', 'Karatay', 'Kulu', 'Meram', 'Sarayönü', 'Selçuklu', 'Seydişehir', 'Taşkent', 'Tuzlukçu', 'Yalıhüyük', 'Yunak'],
  Kütahya: ['Altıntaş', 'Aslanapa', 'Çavdarhisar', 'Domaniç', 'Dumlupınar', 'Emet', 'Gediz', 'Hisarcık', 'Merkez', 'Pazarlar', 'Simav', 'Şaphane', 'Tavşanlı'],
  Malatya: ['Akçadağ', 'Arapgir', 'Arguvan', 'Battalgazi', 'Darende', 'Doğanşehir', 'Doğanyol', 'Hekimhan', 'Kale', 'Kuluncak', 'Pütürge', 'Yazıhan', 'Yeşilyurt'],
  Manisa: ['Ahmetli', 'Akhisar', 'Alaşehir', 'Demirci', 'Gölmarmara', 'Gördes', 'Kırkağaç', 'Köprübaşı', 'Kula', 'Salihli', 'Sarıgöl', 'Saruhanlı', 'Selendi', 'Soma', 'Şehzadeler', 'Turgutlu', 'Yunusemre'],
  Kahramanmaraş: ['Afşin', 'Andırın', 'Çağlayancerit', 'Dulkadiroğlu', 'Ekinözü', 'Elbistan', 'Göksun', 'Nurhak', 'Onikişubat', 'Pazarcık', 'Türkoğlu'],
  Mardin: ['Artuklu', 'Dargeçit', 'Derik', 'Kızıltepe', 'Mazıdağı', 'Midyat', 'Nusaybin', 'Ömerli', 'Savur', 'Yeşilli'],
  Muğla: ['Bodrum', 'Dalaman', 'Datça', 'Fethiye', 'Kavaklıdere', 'Köyceğiz', 'Marmaris', 'Menteşe', 'Milas', 'Ortaca', 'Seydikemer', 'Ula', 'Yatağan'],
  Muş: ['Bulanık', 'Hasköy', 'Korkut', 'Malazgirt', 'Merkez', 'Varto'],
  Nevşehir: ['Acıgöl', 'Avanos', 'Derinkuyu', 'Gülşehir', 'Hacıbektaş', 'Kozaklı', 'Merkez', 'Ürgüp'],
  Niğde: ['Altunhisar', 'Bor', 'Çamardı', 'Çiftlik', 'Merkez', 'Ulukışla'],
  Ordu: ['Akkuş', 'Altınordu', 'Aybastı', 'Çamaş', 'Çatalpınar', 'Çaybaşı', 'Fatsa', 'Gölköy', 'Gülyalı', 'Gürgentepe', 'İkizce', 'Kabadüz', 'Kabataş', 'Korgan', 'Kumru', 'Mesudiye', 'Perşembe', 'Ulubey', 'Ünye'],
  Rize: ['Ardeşen', 'Çamlıhemşin', 'Çayeli', 'Derepazarı', 'Fındıklı', 'Güneysu', 'Hemşin', 'İkizdere', 'İyidere', 'Kalkandere', 'Merkez', 'Pazar'],
  Sakarya: ['Adapazarı', 'Akyazı', 'Arifiye', 'Erenler', 'Ferizli', 'Geyve', 'Hendek', 'Karapürçek', 'Karasu', 'Kaynarca', 'Kocaali', 'Pamukova', 'Sapanca', 'Serdivan', 'Söğütlü', 'Taraklı'],
  Samsun: ['Alaçam', 'Asarcık', 'Atakum', 'Ayvacık', 'Bafra', 'Canik', 'Çarşamba', 'Havza', 'İlkadım', 'Kavak', 'Ladik', 'Ondokuzmayıs', 'Salıpazarı', 'Tekkeköy', 'Terme', 'Vezirköprü', 'Yakakent'],
  Siirt: ['Baykan', 'Eruh', 'Kurtalan', 'Merkez', 'Pervari', 'Şirvan', 'Tillo'],
  Sinop: ['Ayancık', 'Boyabat', 'Dikmen', 'Durağan', 'Erfelek', 'Gerze', 'Merkez', 'Saraydüzü', 'Türkeli'],
  Sivas: ['Akıncılar', 'Altınyayla', 'Divriği', 'Doğanşar', 'Gemerek', 'Gölova', 'Gürün', 'Hafik', 'İmranlı', 'Kangal', 'Koyulhisar', 'Merkez', 'Suşehri', 'Şarkışla', 'Ulaş', 'Yıldızeli', 'Zara'],
  Tekirdağ: ['Çerkezköy', 'Çorlu', 'Ergene', 'Hayrabolu', 'Kapaklı', 'Malkara', 'Marmaraereğlisi', 'Muratlı', 'Saray', 'Süleymanpaşa', 'Şarköy'],
  Tokat: ['Almus', 'Artova', 'Başçiftlik', 'Erbaa', 'Merkez', 'Niksar', 'Pazar', 'Reşadiye', 'Sulusaray', 'Turhal', 'Yeşilyurt', 'Zile'],
  Trabzon: ['Akçaabat', 'Araklı', 'Arsin', 'Beşikdüzü', 'Çarşıbaşı', 'Çaykara', 'Dernekpazarı', 'Düzköy', 'Hayrat', 'Köprübaşı', 'Maçka', 'Of', 'Ortahisar', 'Sürmene', 'Şalpazarı', 'Tonya', 'Vakfıkebir', 'Yomra'],
  Tunceli: ['Çemişgezek', 'Hozat', 'Mazgirt', 'Merkez', 'Nazımiye', 'Ovacık', 'Pertek', 'Pülümür'],
  Şanlıurfa: ['Akçakale', 'Birecik', 'Bozova', 'Ceylanpınar', 'Eyyübiye', 'Halfeti', 'Haliliye', 'Harran', 'Hilvan', 'Karaköprü', 'Siverek', 'Suruç', 'Viranşehir'],
  Uşak: ['Banaz', 'Eşme', 'Karahallı', 'Merkez', 'Sivaslı', 'Ulubey'],
  Van: ['Bahçesaray', 'Başkale', 'Çaldıran', 'Çatak', 'Edremit', 'Erciş', 'Gevaş', 'Gürpınar', 'İpekyolu', 'Muradiye', 'Özalp', 'Saray', 'Tuşba'],
  Yozgat: ['Akdağmadeni', 'Aydıncık', 'Boğazlıyan', 'Çandır', 'Çayıralan', 'Çekerek', 'Kadışehri', 'Merkez', 'Saraykent', 'Sarıkaya', 'Sorgun', 'Şefaatli', 'Yenifakılı', 'Yerköy'],
  Zonguldak: ['Alaplı', 'Çaycuma', 'Devrek', 'Ereğli', 'Gökçebey', 'Kilimli', 'Kozlu', 'Merkez'],
  Aksaray: ['Ağaçören', 'Eskil', 'Gülağaç', 'Güzelyurt', 'Merkez', 'Ortaköy', 'Sarıyahşi', 'Sultanhanı'],
  Bayburt: ['Aydıntepe', 'Demirözü', 'Merkez'],
  Karaman: ['Ayrancı', 'Başyayla', 'Ermenek', 'Kazımkarabekir', 'Merkez', 'Sarıveliler'],
  Kırıkkale: ['Bahşılı', 'Balışeyh', 'Çelebi', 'Delice', 'Karakeçili', 'Keskin', 'Merkez', 'Sulakyurt', 'Yahşihan'],
  Batman: ['Beşiri', 'Gercüş', 'Hasankeyf', 'Kozluk', 'Merkez', 'Sason'],
  Şırnak: ['Beytüşşebap', 'Cizre', 'Güçlükonak', 'İdil', 'Merkez', 'Silopi', 'Uludere'],
  Bartın: ['Amasra', 'Kurucaşile', 'Merkez', 'Ulus'],
  Ardahan: ['Çıldır', 'Damal', 'Göle', 'Hanak', 'Merkez', 'Posof'],
  Iğdır: ['Aralık', 'Karakoyunlu', 'Merkez', 'Tuzluca'],
  Yalova: ['Altınova', 'Armutlu', 'Çınarcık', 'Çiftlikköy', 'Merkez', 'Termal'],
  Karabük: ['Eflani', 'Eskipazar', 'Merkez', 'Ovacık', 'Safranbolu', 'Yenice'],
  Kilis: ['Elbeyli', 'Merkez', 'Musabeyli', 'Polateli'],
  Osmaniye: ['Bahçe', 'Düziçi', 'Hasanbeyli', 'Kadirli', 'Merkez', 'Sumbas', 'Toprakkale'],
  Düzce: ['Akçakoca', 'Cumayeri', 'Çilimli', 'Gölyaka', 'Gümüşova', 'Kaynaşlı', 'Merkez', 'Yığılca'],
}

export const TURKEY_PROVINCES: TurkeyProvince[] = TURKEY_PROVINCE_RAW.map((province) => ({
  ...province,
  normalizedName: normalizeTurkishLocation(province.name),
  districts: (TURKEY_DISTRICTS_BY_PROVINCE[province.name] ?? province.districts).map((district) => ({
    name: district,
    normalizedName: normalizeTurkishLocation(district),
  })),
}))

const TURKEY_PROVINCE_BY_NORMALIZED_NAME = new Map(
  TURKEY_PROVINCES.map((province) => [province.normalizedName, province] as const),
)

export function findProvinceByName(value: string | null | undefined): TurkeyProvince | null {
  const normalized = normalizeTurkishLocation(value)

  if (!normalized) {
    return null
  }

  return TURKEY_PROVINCE_BY_NORMALIZED_NAME.get(normalized) ?? null
}

export function standardizeProvinceName(value: string | null | undefined): string | null {
  return findProvinceByName(value)?.name ?? null
}

export const normalizeProvinceName = standardizeProvinceName

export function getDistrictOptionsForProvince(provinceName: string | null | undefined): TurkeyDistrict[] {
  return findProvinceByName(provinceName)?.districts ?? []
}

export function findDistrictByName(
  provinceName: string | null | undefined,
  districtName: string | null | undefined,
): TurkeyDistrict | null {
  const province = findProvinceByName(provinceName)
  const normalizedDistrict = normalizeTurkishLocation(districtName)

  if (!province || !normalizedDistrict) {
    return null
  }

  return province.districts.find((district) => district.normalizedName === normalizedDistrict) ?? null
}

export function standardizeDistrictName(
  provinceName: string | null | undefined,
  districtName: string | null | undefined,
): string | null {
  return findDistrictByName(provinceName, districtName)?.name ?? null
}

export const normalizeDistrictName = standardizeDistrictName

export function haversineKm(
  fromLat: number | null,
  fromLng: number | null,
  toLat: number | null,
  toLng: number | null,
): number | null {
  if (fromLat === null || fromLng === null || toLat === null || toLng === null) {
    return null
  }

  const toRad = (value: number) => (value * Math.PI) / 180
  const earthKm = 6371
  const dLat = toRad(toLat - fromLat)
  const dLng = toRad(toLng - fromLng)
  const a = Math.sin(dLat / 2) ** 2
    + Math.cos(toRad(fromLat)) * Math.cos(toRad(toLat)) * Math.sin(dLng / 2) ** 2

  return Math.round((earthKm * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))) * 10) / 10
}

export function provinceDistanceKm(
  fromProvinceName: string | null | undefined,
  toProvinceName: string | null | undefined,
): number | null {
  const fromProvince = findProvinceByName(fromProvinceName)
  const toProvince = findProvinceByName(toProvinceName)

  if (!fromProvince || !toProvince) {
    return null
  }

  return haversineKm(fromProvince.latitude, fromProvince.longitude, toProvince.latitude, toProvince.longitude)
}
