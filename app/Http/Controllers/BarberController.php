<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\User;
use App\Models\UserAppointment;
use App\Models\UserFavorite;
use App\Models\Barber;
use App\Models\BarberPhotos;
use App\Models\BarberServices;
use App\Models\BarberTestimonial;
use App\Models\BarberAvailability;

class BarberController extends Controller
{
    private $loggedUser;

    public function __construct() {
        $this->middleware('auth:api');
        $this->loggedUser = auth()->user();
    }
 /*   
    public function createRandom() {
        $array = ['error' => ''];
        for($q=0;$q<15;$q++) {
            $names = ['Bonieky', 'Paulo', 'Pedro', 'Amanda', 'Leticia', 'Gabriel', 'Ronaldo'];
            $lastnames = ['Silva', 'Lacerda', 'Diniz', 'Alvaro', 'Sousa', 'Gomes'];
            $servicos = ['Corte', 'Pintura', 'Aparação', 'Enfeite'];
            $servicos2 = ['Cabelo', 'Unha', 'Pernas', 'Sobrancelhas'];
            $depos = [
                'Maecenas ullamcorper mi a justo egestas ultrices quis eget lacus.',
                'Fusce malesuada justo in maximus auctor. In quis enim in.',
                'Aliquam dapibus id dolor non auctor. Morbi vehicula nec ex.',
                'Sed pulvinar, neque sed blandit fermentum, dui mi sollicitudin turpis.',
                'Nam luctus accumsan enim, a finibus sapien rhoncus fermentum. Praesent.'
            ];
            $newBarber = new Barber();
            $newBarber->name = $names[rand(0, count($names)-1)].' '.$lastnames[rand(0, count($lastnames)-1)];
            $newBarber->avatar = rand(1, 4).'.png';
            $newBarber->stars = rand(2, 4).'.'.rand(0, 9);
            $newBarber->latitude = '-23.5'.rand(0,9).'30907';
            $newBarber->longitude = '-46.6'.rand(0,9).'82795';
            $newBarber->save();
            $ns = rand(3, 6);
            for($w=0;$w<4;$w++) {
                $newBarberPhoto = new BarberPhotos();
                $newBarberPhoto->id_barber = $newBarber->id;
                $newBarberPhoto->url = rand(1, 5).'.png';
                $newBarberPhoto->save();
            }
            for($w=0;$w<$ns;$w++) {
                $newBarberService = new BarberServices();
                $newBarberService->id_barber = $newBarber->id;
                $newBarberService->name = $servicos[rand(0, count($servicos)-1)].' de '.$servicos2[rand(0, count($servicos2)-1)];
                $newBarberService->price = rand(1, 99).'.'.rand(0, 100);
                $newBarberService->save();
            }
            for($w=0;$w<3;$w++) {
                $newBarberTestimonial = new BarberTestimonial();
                $newBarberTestimonial->id_barber = $newBarber->id;
                $newBarberTestimonial->name = $names[rand(0, count($names)-1)].' '.$lastnames[rand(0, count($lastnames)-1)];
                $newBarberTestimonial->rate = rand(2, 4).'.'.rand(0, 9);
                $newBarberTestimonial->body = $depos[rand(0, count($depos)-1)];
                $newBarberTestimonial->save();
            }
            for($e=0;$e<4;$e++) {
                $rAdd = rand(7, 10);
                $hours = [];
                for($r=0;$r<8;$r++) {
                    $time = $r + $rAdd;
                    if($time < 10) {
                        $time = '0'.$time;
                    }
                    $hours[] = $time.':00';
                }
                $newBarberAvail = new BarberAvailability();
                $newBarberAvail->id_barber = $newBarber->id;
                $newBarberAvail->weekday = $e;
                $newBarberAvail->hours = implode(',', $hours);
                $newBarberAvail->save();
            }
        }
        return $array;
    }
*/

private function searchGeo($address) {
    $key = env('MAPS_KEY', null);

    $adress = urlencode($adress);

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?adress='.$adress.'$key='.$key;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res, true);
}

public function list(Request $request) {
    $array = ['error' => ''];

    $lat = $request->input('lat');
    $lng = $request->input('lng');
    $city = $request->input('city');
    $offset = $request->input('offset');
    if(!$offset) {
        $offset = 0;
    }

    if(!empty($city)) {
        $res = $this->searchGeo($city);

        if(count($res['results']) > 0) {
            $lat = $res['results'][0]['geometry']['location']['lat'];
            $lng = $res['results'][0]['geometry']['location']['lng'];
        }
    } elseif(!empty($lat) && !empty($lng)) {
        $res = $this->searchGeo($lat.','.$lng);

        if(count($res['results']) > 0) {
            $city = $res['results'][0]['formatted_adress'];
        }
    } else {
        $lat = '-23.5630907';
        $lng = '-46.6682795';
        $city = 'São Paulo';
    }

    $barbers = Barber::select(Barber::raw('*, SQRT(
        POW(69.1 * (latitude - '.$lat.'), 2) +
        POW(69.1 * ('.$lng.' - longitude) * COS(latitude / 57.3), 2)) AS distance'))
        ->havingRaw('distance < ?', [10])
        ->orderBy('distance', 'ASC')
        ->offset($offset)
        ->limit(5)
        ->get();

    foreach($barbers as $bkey => $bvalue) {
        $barbers[$bkey]['avatar'] = url('media/avatars/'.$barbers[$bkey]['avatar']);
    }

    $array['data'] = $barbers;
    $array['loc'] = 'São Paulo';

    return $array;
}


    public function one($id) {
        $array = ['error' =>''];

        $barber = Barber::find($id);

        if($barber) {
            $barber['avatar'] = url('media/avatars'.$barber['avatar']);
            $barber['favorited'] = false;
            $barber['photos'] = [];
            $barber['services'] = [];
            $barber['testimonials'] = [];
            $barber['available'] = [];

            // verificando favorito 
            $cFavorite = UserFavorite::where('id_user', $this->loggedUser->id)
            ->where('id_barber', $barber->id)
            ->count();

                if($cFavorite > 0) {
                    $barber['favorited'] = true;
                }

            // pegando as fotos dos barbeiros;
            $barber['photos'] = BarberPhotos::select(['id', 'url'])->where('id_barber', $barber->id)->get();
            foreach($barber['photos'] as $bpkey => $bpvalue) {
                $barber['photos'][$bpkey]['url'] = url('media/uploads/'.$barber['photos'][$bpkey]['url']);
            }


            // pegando os serviços do barbeiro
            $barber['services'] = BarberServices::select(['id', 'name', 'price'])->where('id_barber', $barber->id)->get();



            // pegando os depoimentos do barbeiro

            $barber['testimonials'] = BarberTestimonial::select(['id', 'name', 'rate', 'body'])->where('id_barber', $barber->id)->get();



            // pegando disponibilidade do barbeiro 
            
            $availability = [];

            //- pegando a disponibilidade crua
            $avails = BarberAvailability::where('id_barber', $barber->id)->get();
            $availWeekdays = [];
            foreach($avails as $item) {
                $availWeekdays[$item['weekday']] = explode(',', $item['hours']);
            }

            // pegar os agendamentos dos proximos 20 dias 
            $appointments = [];
            $appQuery = UserAppointment::where('id_barber', $barber->id)
                ->whereBetween('ap_datetime', [
                    date('Y-m-d').' 00:00:00',
                    date('Y-m-d', strtotime('+20 days')).' 23:59:59'
                ])
                ->get();

                foreach($appQuery as $appItem) {
                    $appointments[] = $appItem['ap_datetime'];
                }
    


                 // - gerar disponibilidade real
                 for($q=0;$q<20;$q++) {
                    $timeItem = strtotime('+'.$q.' days');
                    $weekday = date('w', $timeItem);
                    
                    if(in_array($weekday, array_keys($availWeekdays))) {
                        $hours = [];

                        $dayItem = date('Y-m-d', $timeItem);

                        foreach($availWeekdays[$weekday] as $hourItem) {
                            $dayFormated = $dayItem.' '.$hourItem.':00';
                            if(!in_array($dayFormated, $appointments)) {
                                $hours[] = $hourItem;
                            }
                        }

                        if(count($hours) > 0) {
                            $availability[] = [
                                'date' => $dayItem,
                                'hours' => $hours
                            ];
                        }
    
                    }
                }

            $barber['available'] = $availability;

            $array['data'] = $barber;
        } else {
            $array['error'] = 'Barbeiro não existe!';
            return $array;
        }

        return $array;
        
    }
}

