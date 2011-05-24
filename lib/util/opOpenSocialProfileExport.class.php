<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * opOpenSocialProfileExport
 *
 * @package    opOpenSocialPlugin
 * @subpackage util
 * @author     Shogo Kawahara <kawahara@bucyou.net>
 */
class opOpenSocialProfileExport extends opProfileExport
{
  protected
    $viewer = null,
    $supportedFieldExport = null;

  public $tableToOpenPNE = array(
    'displayName'    => 'name',
    'nickname'       => 'name',
    'thumbnailUrl'   => 'image',
    'profileUrl'     => 'profile_url',
    'addresses'      => 'addresses',
    'aboutMe'        => 'op_preset_self_introduction',
    'gender'         => 'op_preset_sex',
// 'age' is not working now
    'phoneNumbers'   => 'op_preset_telephone_number',
    'birthday'       => 'op_preset_birthday',
    'languagesSpoken'=> 'language',
  );

  public $profiles = array(
    'addresses',
    'aboutMe',
    'gender',
    'age',
    'phoneNumbers',
    'birthday',
  );

  public $names = array(
    'displayName',
    'nickname',
  );

  public $images = array(
    'thumbnailUrl',
  );

  public $configs = array(
    'profileUrl',
    'languagesSpoken',
  );

  public $forceFields = array(
    'displayName',
    'profileUrl',
    'thumbnailUrl',
  );

  protected function getProfile($name)
  {
    static $profileIds = array();
    if (!isset($profileIds[$name]))
    {
      $profileIds[$name] = Doctrine::getTable('Profile')->createQuery()
        ->addWhere('name = ?', $name)
        ->fetchOne()->id;
    }

    $profileId = $profileIds[$name];

    foreach ($this->member['MemberProfile'] as $memberProfile)
    {
      if ($memberProfile['profile_id'] === $profileId)
      {
        $profile = $memberProfile;
        break;
      }
    }

    if (!$profile)
    {
      return '';
    }

    if (null !== $this->viewer)
    {
      $viewerId = $this->viewer->id;
      switch ($profile['public_flag'])
      {
        case ProfileTable::PUBLIC_FLAG_FRIEND:
          $relation = $this->member['MemberRelationship'];
          if (!empty($relation['member_id_to']) && $relation['is_friend'])
          {
            $isViewable = true;
          }
          else
          {
            $isViewable = ($this->member['id'] === $viewerId);
          }
          break;
        case ProfileTable::PUBLIC_FLAG_PRIVATE:
          $isViewable = false;
          break;
        case ProfileTable::PUBLIC_FLAG_SNS:
          $isViewable = true;
          break;
        default:
          $isViewable = false;
          break;
      }

      if ($viewerId !== $this->member['id'] && !$isViewable)
      {
        return '';
      }
    }
    else
    {
      if ($profile['public_flag'] !== 0)
      {
        return '';
      }
    }

    return (string)$profile['value'];
  }

  public function __call($name, $arguments)
  {
    if (0 === strpos($name, 'get'))
    {
      $key = substr($name, 3);
      $key = strtolower($key[0]).substr($key, 1, strlen($key));

      if (in_array($key, $this->profiles))
      {
        return (string)$this->getProfile($this->tableToOpenPNE[$key]);
      }
      elseif (in_array($key, $this->names))
      {
        return $this->member['name'];
      }
      elseif (in_array($key, $this->emails))
      {
        return $this->member->getEmailAddress();
      }
      elseif (in_array($key, $this->images))
      {
        return $this->getMemberImageURI();
      }
      elseif (in_array($key, $this->configs))
      {
        return Doctrine::getTable('MemberConfig')->getValue($this->member['id'], $this->tableToOpenPNE[$key]);
      }
    }

    throw new BadMethodCallException(sprintf('Unknown method %s::%s', get_class($this), $name));
  }

  /**
   * get profile datas
   *
   * @param array $allowed
   * @return array
   */
  public function getData($allowed = array())
  {
    $result  = array();
    $allowed = array_merge($this->forceFields, $allowed);
    $isBlock = false;

    // check access block
    if ($this->viewer)
    {
      $relation = $this->member['MemberRelationship'];

      if (!empty($relation['member_id_to']) && $relation['is_access_block'])
      {
        $isBlock = true;
      }
    }

    $tableToOpenPNE = array_intersect_key($this->tableToOpenPNE, array_flip($allowed));

    foreach ($tableToOpenPNE as $k => $v)
    {
      $checkSupportMethodName = $this->getSupportedFieldExport()->getIsSupportedMethodName($k);
      if ($this->getSupportedFieldExport()->$checkSupportMethodName())
      {
        if ($isBlock)
        {
          $result[$k] = '';
        }
        else
        {
          $methodName = $this->getGetterMethodName($k);
          $result[$k] = opOpenSocialToolKit::convertEmojiForApi($this->$methodName());
        }
      }
    }

    return $result;
  }

  public function setViewer(Member $member)
  {
    $this->viewer = $member;
  }

  public function getViewer()
  {
    return $this->viewer;
  }

  public function getSupportedFieldExport()
  {
    if ($this->supportedFieldExport === null)
    {
      $this->supportedFieldExport = new opOpenSocialSupportedFieldExport($this);
    }
    return $this->supportedFieldExport;
  }

  public function getSupportedFields()
  {
    return $this->getSupportedFieldExport()->getSupportedFields();
  }

  public function getAddresses()
  {
    $result = array();
    $unstructured = array();
    $region = $this->getProfile('op_preset_region');
    if ($region)
    {
      $result['region'] = $region;
      $unstructured[] = $region;
    }

    $country = $this->getProfile('op_preset_country');
    if ($country)
    {
      $result['country'] = $country;
      $unstructured[] = $country;
    }

    $postalCode = $this->getProfile('op_preset_postal_code');
    if ($postalCode)
    {
      $result['postalCode'] = $postalCode;
    }

    if (count($unstructured))
    {
      $result['formatted'] = implode(',', $unstructured);
    }

    return array($result);
  }

  public function getGender()
  {
    $sex = $this->getProfile('op_preset_sex');
    if (!$sex)
    {
      return '';
    }
    return ('Man' == $sex ? 'male' : 'female');
  }

  public function getBirthday()
  {
    $birth = $this->getProfile('op_preset_birthday');
    if (!$birth)
    {
      return '';
    }
    $age = $this->getAge();
    if (!$age)
    {
      // age of the person is private
      return date('0000-m-d', strtotime($birth));
    }

    return date('Y-m-d', strtotime($birth));
  }

  public function getAge()
  {
    if (null !== $this->viewer && $this->viewer->getId() !== $this->member->getId())
    {
      $age = $this->member->getAge(true, $this->viewer->getId());
    }
    else
    {
      $age = $this->member->getAge(false, $this->member->getId());
    }

    return (false !== $age) ? $age : '';
  }

  public function getPhoneNumbers()
  {
    $number = $this->getProfile('op_preset_telephone_number');

    return array(array('value' => $number));
  }

  public function getThumbnailUrl()
  {
    sfContext::getInstance()->getConfiguration()->loadHelpers(array('Asset', 'sfImage'));
    $image = $this->member['MemberImage'];

    if (!empty($image['file_id']))
    {
      $file = Doctrine::getTable('File')->find($image['file_id']);
      return sf_image_path($file, array(), true);
    }
    return '';
  }

  public function getProfileUrl()
  {
    return sfConfig::get('op_base_url').'/member/'.$this->member['id'];
  }

  public function getLanguagesSpoken()
  {
    $language = $this->member->getConfig('language');
    return substr($language, 0, strpos($language, '_'));
  }
}
