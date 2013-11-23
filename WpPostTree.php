<?php
/**
 * Requires: PHP 5.3+
 * Usage: <?php echo new WpPostTree($post); ?>
 *
 * Produces a tree of navigation links, relative to $post.
 * Optional second parameter is an array of customization options.
 *
 * @author Daniel Baumann
 **/
class WpPostTree {
  private $options;
  public $currentPost;
  private $rootPost;
  private $allPosts;
  private $tree;

  public function __construct($currentPost, $options=array()) {

    $optionDefaults = array(
      'only_descendants' => false,                        //don't ascend up the post tree to the top level parent
      'full_tree' => false,                               //don't hide the parts of the tree that aren't directly related to the current post
      'current_post_class' => "WpPostTree-current-post",  //class added to link if it is the current post
      'hidden_meta_key' => "hideInNav"                    //hideInNav = true hides any post from navigation
    );

    foreach($optionDefaults as $key => $value) {
      if(!array_key_exists($key, $options)) $options[$key] = $value;
    }

    $this->options = $options;
    $this->currentPost = $currentPost;
    if(!$this->options['only_descendants']) {
      $this->rootPost = self::getTopAncestor($this->currentPost);
    } else {
      $this->rootPost = $this->currentPost;
    }
  
    //collect the root post with all of its children
    $allPosts = array_merge(array($this->rootPost), get_pages(array('child_of' => $this->rootPost->ID)));

    //filter out specifically hidden nodes
    $query = new WP_Query(); //must get custom fields with a separate query
    $results = $query->query(array(
      'showposts' => -1,
      'post_type' => "page",
      'meta_key' => $this->options['hidden_meta_key'],
      'meta_value' => "true"
    ));

    $hiddenPostIds = array();
    foreach($results as $post) $hiddenPostIds []= $post->ID;

    foreach($allPosts as $index => $post) {
      if(in_array($post->ID, $hiddenPostIds)) unset($allPosts[$index]);
    }

    $this->allPosts = $allPosts;

    //build a tree of posts
    $tree = $this->generateTree($this->rootPost);

    //prune out branches that aren't siblings of nodes on the path to the current post
    if(!$this->options['full_tree']) {
      $self = $this;
      $tree = $this->pruneTree($tree, function($branch) use ($self) {
        return $branch[0]->ID != $self->currentPost->ID && !$self->isDescendent($branch[0]->ID, $self->currentPost->ID);
      });
    }
    $this->tree = $tree;
  }

  public function __toString() {
    return "<ul>" . $this->generateMarkup($this->tree) . "</ul>";
  }

  private function generateTree($post) {
    $node = array(0 => $post, 1 => array());
    foreach($this->getChildren($post->ID) as $child) {
      $node[1] []= $this->generateTree($child);
    }
    return $node;
  }

  private function getDescendents($postId) {
    return get_page_children($postId, $this->allPosts);
  }

  private function getChildren($postId) {
    $children = array();
    foreach($this->getDescendents($postId) as $p) {
      if($p->post_parent == $postId) $children []= $p;
    }
    return $children;
  }

  public function isDescendent($parentId, $childId) {
    foreach($this->getDescendents($parentId) as $descendent) {
      if($descendent->ID == $childId) return true;
    }
    return false;
  }

  //returns a modified tree without the CHILDREN of any branch node for which $callback returns true;
  //otherwise the original tree is returned
  private function pruneTree($tree, $callback) {
    if(empty($tree[1])) {
      return $tree;
    } else if(call_user_func($callback, $tree)) {
      return array($tree[0], array());
    } else {
      $children = array();
      foreach($tree[1] as $child) $children []= $this->pruneTree($child, $callback);
      return array($tree[0], $children);
    }
  }

  private function generateMarkup($tree) {
    $title = $tree[0]->post_title;
    $permalink = get_permalink($tree[0]->ID);

    $linkAttributes = array(
      array('href', $permalink),
      array('class', ($tree[0]->ID == $this->currentPost->ID ? $this->options['current_post_class']: ""))
    );
    $linkAttributeString = array_reduce($linkAttributes, function($acc, $attributePair) {
      if(!empty($attributePair[1])) $acc .= " {$attributePair[0]}=\"{$attributePair[1]}\"";
      return $acc;
    });
    $link = sprintf("<a %s>%s</a>", $linkAttributeString, $title);

    //leaf
    if(empty($tree[1])) return "<li>$link</li>\n";
    else {
      //fork
      $out = "<li>$link<ul>";
      foreach($tree[1] as $child) $out .= $this->generateMarkup($child);
      $out .= "</ul></li>\n";

      return $out;
    }
  }

  private static function getTopAncestor($post) {
    if($post->post_parent){
      $ancestors = array_reverse(get_post_ancestors($post->ID));
      return get_post($ancestors[0]);
    }
    else return $post;
  }
}
?>
