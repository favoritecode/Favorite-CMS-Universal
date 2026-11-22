<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use FavoriteCMS\Core\{Application, Database, Hook};
use FavoriteCMS\Models\{User, Setting};
use FavoriteCMS\Plugins\PluginManager;

/** Read-only dashboard providers. Filesystem presence, permissions and active state are independent gates. */
final class DashboardService
{
    private Database $db;
    private array $columns = [];
    private array $rawTables = [];
    public function __construct(private Application $app) { $this->db=$app->make(Database::class); }

    public function sources(User $user): array
    {
        $sources=[];
        $core=[
            'posts'=>['Posts','Your content and publication status',$user->canCreatePosts()||$user->canUpdatePosts()],
            'pages'=>['Pages','Published pages and drafts',$user->canManagePages()],
            'comments'=>['Comments','Comment moderation overview',$user->canModerateComments()],
            'users'=>['Users','Registered accounts',$user->canManageUsers()],
            'media'=>['Media','Your media library',$user->canUploadMedia()||$user->canManageMedia()],
            'account'=>['My account','Profile and account shortcuts',true],
        ];
        foreach($core as $key=>[$title,$description,$allowed]) if($allowed) $sources['core-'.$key]=[
            'id'=>'core-'.$key,'title'=>$title,'description'=>$description,'group'=>'CMS','plugin'=>null,'active'=>true,'kind'=>$key,
        ];
        // Never infer installation from leftover SQL tables or saved preferences.
        $installed=(new PluginManager($this->app))->getInstalledPlugins();
        if($user->hasPermission('manage_options')) foreach([
            'favorite-multimedia'=>['Favorite Multimedia','Movies, series, episodes and songs'],
            'favorite-digital'=>['Favorite Digital','Products, orders and memberships'],
            'favorite-shop'=>['Favorite Shop','Products, orders and stock alerts'],
            'favorite-app-store'=>['Favorite App Store','Apps, software and source activity'],
        ] as $id=>[$title,$description]) {
            if(!isset($installed[$id])||empty($installed[$id]['valid']))continue;
            $meta=$installed[$id];
            $sources[$id]=['id'=>$id,'title'=>$title,'description'=>$description,'group'=>'Plugins','plugin'=>$id,
                'active'=>!empty($meta['active'])&&!empty($meta['compatible']),'tables'=>array_values(array_filter(is_array($meta['tables']??null)?$meta['tables']:[],'is_string')),'kind'=>$id];
        }
        // Plugins can add a safe callable provider without editing Core.
        $extra=Hook::applyFilters('dashboard_sources',[],$user);
        if(is_array($extra))foreach($extra as $source){
            if(!is_array($source)||empty($source['id'])||!preg_match('/^[a-z0-9_-]{1,80}$/D',(string)$source['id']))continue;
            $id=(string)$source['id'];$plugin=(string)($source['plugin']??'');
            if(isset($sources[$id])||!isset($installed[$plugin])||empty($installed[$plugin]['valid'])||!is_callable($source['load']??null))continue;
            try{$allowed=is_callable($source['allowed']??null)?(bool)$source['allowed']($user):$user->hasPermission('manage_options');}catch(\Throwable){$allowed=false;}
            if(!$allowed)continue;
            $sources[$id]=['id'=>$id,'title'=>(string)($source['title']??$installed[$plugin]['name']),'description'=>(string)($source['description']??''),'group'=>'Plugins','plugin'=>$plugin,
                'active'=>!empty($installed[$plugin]['active'])&&!empty($installed[$plugin]['compatible']),'load'=>$source['load']];
        }
        return $sources;
    }

    public function selected(User $user, array $sources): array
    {
        $saved=Setting::get('dashboard','user_'.(int)$user->id,null);
        if($saved===null) return array_values(array_filter(array_keys($sources),static fn($id)=>str_starts_with($id,'core-')&&$id!=='core-account')) ?: ['core-account'];
        $saved=is_array($saved)?$saved:json_decode((string)$saved,true);
        if(!is_array($saved))$saved=[];
        return array_values(array_unique(array_filter($saved,static fn($id)=>is_string($id)&&isset($sources[$id]))));
    }

    public function save(User $user, array $sources, mixed $selection): void
    {
        if(!is_array($selection)||count($selection)>100)throw new \InvalidArgumentException('Choose valid dashboard sources.');
        foreach($selection as $id)if(!is_string($id)||!isset($sources[$id]))throw new \InvalidArgumentException('A selected source is no longer available. Reload the dashboard.');
        $previous=$this->selected($user,$sources);
        $order=array_values(array_intersect($previous,$selection));
        foreach($selection as $id)if(!in_array($id,$order,true))$order[]=$id;
        Setting::set('dashboard','user_'.(int)$user->id,$order,'json');
    }

    public function reorder(User $user,array $sources,mixed $order):array
    {
        if(!is_array($order)||count($order)>100)throw new \InvalidArgumentException('Invalid dashboard order.');
        foreach($order as $id)if(!is_string($id)||!isset($sources[$id]))throw new \InvalidArgumentException('A dashboard source changed. Reload this page.');
        $current=$this->selected($user,$sources);$expected=$current;$received=$order;sort($expected);sort($received);
        if($expected!==$received||count(array_unique($order))!==count($order))throw new \InvalidArgumentException('Only selected dashboard panels can be reordered. Reload this page.');
        $order=array_values($order);Setting::set('dashboard','user_'.(int)$user->id,$order,'json');return $order;
    }

    public function panels(User $user, array $sources, array $selected): array
    {
        $panels=[];
        foreach($selected as $id){
            if(!isset($sources[$id]))continue;
            $source=$sources[$id];
            $panel=['id'=>$id,'title'=>$source['title'],'description'=>$source['description'],'plugin'=>$source['plugin'], 'metrics'=>[],'recent'=>[],'actions'=>[],'notice'=>''];
            if(!$source['active']){$panel['notice']='This installed plugin is inactive or has an unmet requirement. Activate it to load its dashboard data.';$panels[]=$panel;continue;}
            try{
                if(isset($source['load'])){$data=$source['load']($user,$this->db);if(!is_array($data))throw new \RuntimeException('Invalid dashboard provider response.');$panel=array_replace($panel,array_intersect_key($data,array_flip(['metrics','recent','actions','notice'])));}
                elseif($source['plugin']===null)$panel=$this->core($user,$source['kind'],$panel);
                else $panel=$this->plugin($source,$panel);
            }catch(\Throwable $e){error_log('Dashboard source '.$id.': '.$e->getMessage());$panel['notice']='This data is temporarily unavailable. Other dashboard sources remain available.';}
            foreach(['metrics'=>12,'recent'=>10,'actions'=>6] as $key=>$maximum)$panel[$key]=array_slice(array_values(array_filter(is_array($panel[$key]??null)?$panel[$key]:[],'is_array')),0,$maximum);
            $panel['notice']=is_string($panel['notice'])?$panel['notice']:'';
            $panels[]=$panel;
        }
        return $panels;
    }

    private function core(User $user,string $kind,array $panel):array
    {
        if($kind==='account'){
            $panel['description']='Signed in as '.($user->name?:$user->username);
            $panel['actions']=[['label'=>'Edit profile','url'=>'/admin/users/profile'],['label'=>'Security & email','url'=>'/admin/account/security']];return $panel;
        }
        $tables=['posts'=>'posts','pages'=>'pages','comments'=>'comments','users'=>'users','media'=>'media'];$table=$tables[$kind];
        $where='1=1';$params=[];
        if($kind==='posts'){$where="type='post'";if(!$user->canModeratePosts()){$where.=' AND author_id=?';$params[]=(int)$user->id;$panel['title']='My posts';}}
        if($kind==='media'&&!$user->canManageMedia()){$where='uploader_id=?';$params[]=(int)$user->id;$panel['title']='My media';}
        $groups=$this->groups($table,$where,$params);
        if(in_array($kind,['posts','pages'],true)){
            $total=array_sum($groups)-($groups['trash']??0);
            $panel['metrics']=[['label'=>'Total','value'=>$total],['label'=>'Published','value'=>$groups['published']??0],['label'=>'Drafts','value'=>$groups['draft']??0],['label'=>'Scheduled','value'=>$groups['scheduled']??0]];
            if($kind==='posts'&&($groups['pending']??0)>0)$panel['metrics'][]=['label'=>'Pending review','value'=>$groups['pending']];
            $where.=" AND status<>'trash'";
            $rows=$this->select('SELECT id,title,slug,status,updated_at,author_id FROM `'.$table.'` WHERE '.$where.' ORDER BY updated_at DESC,id DESC LIMIT 5',$params);
            foreach($rows as $r){$model=$kind==='posts'?new \FavoriteCMS\Models\Post((array)$r):new \FavoriteCMS\Models\Page((array)$r);$editable=$kind==='pages'||$user->canEditPost($model);$panel['recent'][]=['title'=>$r->title,'status'=>$r->status,'date'=>$r->updated_at,'url'=>$editable?'/admin/'.$table.'/edit?id='.(int)$r->id:$model->url()];}
            $panel['actions'][]=['label'=>$kind==='posts'?'Manage posts':'Manage pages','url'=>'/admin/'.$table];
            if($kind==='pages'||$user->canCreatePosts())$panel['actions'][]=['label'=>'Create '.rtrim($kind,'s'),'url'=>'/admin/'.$table.'/new'];
            $panel['quick_draft']=$kind==='posts'&&$user->canCreatePosts();
        }elseif($kind==='comments'){
            $panel['metrics']=[['label'=>'Total','value'=>array_sum($groups)],['label'=>'Pending','value'=>$groups['pending']??0],['label'=>'Approved','value'=>$groups['approved']??0],['label'=>'Spam','value'=>$groups['spam']??0]];
            $panel['actions'][]=['label'=>'Moderate comments','url'=>'/admin/comments'];
        }elseif($kind==='users'){
            $panel['metrics']=[['label'=>'Registered users','value'=>array_sum($groups)],['label'=>'Active','value'=>$groups['active']??0],['label'=>'Suspended','value'=>$groups['suspended']??0]];
            $panel['actions'][]=['label'=>'Manage users','url'=>'/admin/users'];
        }else{$panel['metrics']=[['label'=>'Media files','value'=>array_sum($groups)]];$panel['actions'][]=['label'=>'Open media library','url'=>'/admin/media'];}
        return $panel;
    }

    private function groups(string $table,string $where='1=1',array $params=[]):array
    {
        $columns=$this->tableColumns($table);if(!$columns)throw new \RuntimeException('Dashboard table unavailable.');
        if(!in_array('status',$columns,true))return ['all'=>(int)($this->selectOne('SELECT COUNT(*) AS n FROM `'.$table.'` WHERE '.$where,$params)->n??0)];
        $groups=[];foreach($this->select('SELECT status,COUNT(*) AS n FROM `'.$table.'` WHERE '.$where.' GROUP BY status',$params) as $row)$groups[(string)$row->status]=(int)$row->n;return $groups;
    }
    /** Read-only compatibility with Shop editions that create unprefixed tables through PDO. */
    private function select(string $sql,array $params=[]):array
    {
        foreach($this->rawTables as $table=>$_)if(str_contains($sql,'`'.$table.'`')){
            $statement=$this->db->getPdo()->prepare($sql);$statement->execute($params);
            return $statement->fetchAll(\PDO::FETCH_OBJ);
        }
        return $this->db->select($sql,$params);
    }
    private function selectOne(string $sql,array $params=[]):?object
    {
        return $this->select($sql,$params)[0]??null;
    }
    private function tableColumns(string $table):array
    {
        if(isset($this->columns[$table]))return $this->columns[$table];
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))return [];
        $this->db->registerPrefixableTables([$table]);$prefixed=$this->db->table($table);
        $candidates=str_starts_with($table,'favorite_shop_')?[$table,$prefixed]:[$prefixed];
        if(str_starts_with($table,'favorite_')||str_starts_with($table,'multimedia_'))$candidates[]=$table;
        $pdo=$this->db->getPdo();
        foreach(array_unique($candidates) as $physical){
            $quoted=$this->db->quoteIdentifier($physical,false);
            try{
                $pdo->query('SELECT 1 FROM '.$quoted.' LIMIT 1');
                $rows=$pdo->query($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'PRAGMA table_info('.$quoted.')':'SHOW COLUMNS FROM '.$quoted)->fetchAll(\PDO::FETCH_OBJ);
                if($physical===$table&&$prefixed!==$table)$this->rawTables[$table]=true;
                return $this->columns[$table]=array_map(static fn($c)=>$c->name??$c->Field,$rows);
            }catch(\PDOException){continue;}
        }
        return $this->columns[$table]=[];
    }

    private function plugin(array $source,array $panel):array
    {
        $id=$source['id'];
        $definitions=match($id){
            'favorite-multimedia'=>[['multimedia_movies','Movies','/admin/page/multimedia-movies'],['multimedia_series','Series','/admin/page/multimedia-series'],['multimedia_episodes','Episodes','/admin/page/multimedia-episodes'],['multimedia_songs','Songs','/admin/page/multimedia-songs']],
            'favorite-digital'=>[['favorite_digital_products','Products','/admin/page/favorite-digital'],['favorite_digital_orders','Orders','/admin/page/favorite-digital-orders'],['favorite_digital_memberships','Memberships','/admin/page/favorite-digital-memberships']],
            'favorite-app-store'=>[['favorite_app_store_apps','Apps','/admin/page/favorite-app-store'],['favorite_app_store_sources','Sources','/admin/page/favorite-app-store?tab=sources']],
            'favorite-shop'=>[['favorite_shop_products','Products','/admin/page/favorite-shop-products'],['favorite_shop_orders','Orders','/admin/page/favorite-shop-orders'],['favorite_shop_product_categories','Categories','/admin/page/favorite-shop-categories'],['favorite_shop_coupons','Coupons','/admin/page/favorite-shop-coupons']],
            default=>[],
        };
        $recentLoaded=false;
        foreach($definitions as [$table,$label,$url]){
            if(!in_array($table,$source['tables'],true))continue;
            try{
                $columns=$this->tableColumns($table);if(!$columns)continue;
                $groups=$this->groups($table);$total=array_sum($groups)-($groups['trash']??0);
                $parts=[];foreach(['published'=>'published','draft'=>'drafts','scheduled'=>'scheduled','pending'=>'pending','active'=>'active','processing'=>'processing','shipped'=>'shipped','delivered'=>'delivered','cancelled'=>'cancelled'] as $key=>$text)if(isset($groups[$key]))$parts[]=$groups[$key].' '.$text;
                if(in_array('payment_status',$columns,true)){
                    $paid=(int)($this->selectOne('SELECT COUNT(*) AS n FROM `'.$table.'` WHERE payment_status=?',['paid'])->n??0);$parts[]=$paid.' paid';
                }
                $panel['metrics'][]=['label'=>$label,'value'=>$total,'detail'=>implode(' · ',$parts),'url'=>$url];
                $title=in_array('title',$columns,true)?'title':(in_array('order_number',$columns,true)?'order_number':(in_array('name',$columns,true)?'name':null));
                if((!$recentLoaded||str_ends_with($table,'_orders'))&&$title&&in_array('id',$columns,true)){
                    if(str_ends_with($table,'_orders'))$panel['recent']=[];
                    $fields='id,`'.$title.'` AS title';$fields.=in_array('status',$columns,true)?',status':",'' AS status";
                    $date=in_array('updated_at',$columns,true)?'updated_at':(in_array('created_at',$columns,true)?'created_at':(in_array('placed_at',$columns,true)?'placed_at':null));
                    $fields.=$date?',`'.$date.'` AS date':",NULL AS date";
                    $where=in_array('status',$columns,true)?" WHERE status<>'trash'":'';
                    $rows=$this->select('SELECT '.$fields.' FROM `'.$table.'`'.$where.' ORDER BY '.($date?'`'.$date.'` DESC,':'').'id DESC LIMIT 5');
                    foreach($rows as $row)$panel['recent'][]=['title'=>$row->title,'status'=>$row->status,'date'=>$row->date,'url'=>str_ends_with($table,'_orders')?$url.'?action=view&id='.(int)$row->id:$url];
                    $panel['recent_label']='Recent '.strtolower($label);$recentLoaded=true;
                }
            }catch(\Throwable $e){error_log('Dashboard metric '.$table.': '.$e->getMessage());}
        }
        if($id==='favorite-shop'&&in_array('favorite_shop_products',$source['tables'],true)){
            $columns=$this->tableColumns('favorite_shop_products');
            if(!array_diff(['manage_stock','stock_quantity','low_stock_threshold','allow_backorder'],$columns)){
                $low=(int)($this->selectOne('SELECT COUNT(*) AS n FROM `favorite_shop_products` WHERE manage_stock=1 AND stock_quantity>0 AND stock_quantity<=low_stock_threshold')->n??0);
                $out=(int)($this->selectOne('SELECT COUNT(*) AS n FROM `favorite_shop_products` WHERE manage_stock=1 AND stock_quantity<=0 AND allow_backorder=0')->n??0);
                $panel['metrics'][]=['label'=>'Stock alerts','value'=>$low+$out,'detail'=>$low.' low stock · '.$out.' out of stock','url'=>'/admin/page/favorite-shop-products'];
            }
        }
        if($id==='favorite-multimedia'&&in_array('multimedia_auto_jobs',$source['tables'],true)&&$this->tableColumns('multimedia_auto_jobs')){
            $jobs=$this->groups('multimedia_auto_jobs');$panel['metrics'][]=['label'=>'Auto-post queue','value'=>($jobs['queued']??0)+($jobs['processing']??0),'detail'=>($jobs['scheduled']??0).' scheduled · '.(($jobs['blocked']??0)+($jobs['failed']??0)).' need attention','url'=>'/admin/page/multimedia-auto-post?tab=queue'];
        }
        if(!$panel['metrics'])$panel['notice']='No compatible data tables are ready yet. Finish this plugin’s installation or update.';
        else $panel['actions'][]=['label'=>'Open '.$source['title'],'url'=>$definitions[0][2]??'/admin'];
        return $panel;
    }
}
